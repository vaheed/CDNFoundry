<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Enums\DomainLifecycleState;
use App\Jobs\ReconcileDnsZone;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Support\DnsRecordData;
use App\Support\DnsZoneValidator;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DnsRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'dnsRecords';

    protected static ?string $title = 'DNS records';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->options(function (): array {
                $types = collect(DnsRecordData::TYPES)
                    ->reject(fn (string $type): bool => $type === 'NS' && auth()->user()?->isAdmin() !== true)
                    ->reject(fn (string $type): bool => $type === 'PTR' && ! str_ends_with($this->getOwnerRecord()->name, '.in-addr.arpa') && ! str_ends_with($this->getOwnerRecord()->name, '.ip6.arpa'));

                return $types->mapWithKeys(fn (string $type): array => [$type => $type])->all();
            })->required()->live(),
            Select::make('mode')->options(['dns_only' => 'DNS only', 'geo_dns' => 'Geo-DNS'])
                ->default('dns_only')->required()->live()
                ->helperText('Geo-DNS supports A/AAAA. Without trusted ECS, location represents the recursive resolver.'),
            TextInput::make('name')->required()->default('@')->maxLength(253),
            TextInput::make('content')->required(fn ($get): bool => $get('mode') !== 'geo_dns')->maxLength(4096)
                ->visible(fn ($get): bool => $get('mode') !== 'geo_dns'),
            Textarea::make('geo')->label('Geo-DNS configuration (JSON)')->rows(12)
                ->visible(fn ($get): bool => $get('mode') === 'geo_dns')
                ->required(fn ($get): bool => $get('mode') === 'geo_dns')
                ->formatStateUsing(fn ($state, ?DnsRecord $record): string => json_encode($record?->geo_config ?? $state ?? [
                    'default' => [], 'continents' => new \stdClass, 'countries' => new \stdClass,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                ->dehydrateStateUsing(function ($state): array {
                    $decoded = json_decode((string) $state, true);
                    if (! is_array($decoded)) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['geo' => 'Geo-DNS configuration must be valid JSON.']);
                    }
                    return $decoded;
                })->helperText('Country overrides win over continent overrides. Limits: 64 countries, 7 continents, 8 targets per set.'),
            TextInput::make('ttl')->numeric()->required()->default(300)->minValue(30)->maxValue(2147483647),
            TextInput::make('priority')->numeric()->default(0)->minValue(0)->maxValue(65535)->visible(fn ($get): bool => in_array($get('type'), ['MX', 'SRV'], true)),
            TextInput::make('weight')->numeric()->default(0)->minValue(0)->maxValue(65535)->visible(fn ($get): bool => $get('type') === 'SRV'),
            TextInput::make('port')->numeric()->default(0)->minValue(0)->maxValue(65535)->visible(fn ($get): bool => $get('type') === 'SRV'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('content')->limit(64),
            TextColumn::make('ttl')->sortable(),
            TextColumn::make('mode')->badge(),
        ])->headerActions([
            CreateAction::make()->createAnother(false)->using(function (array $data): DnsRecord {
                return $this->createRecord($data);
            }),
        ])->recordActions([
            EditAction::make()->visible(fn (DnsRecord $record): bool => $record->type !== 'NS' || auth()->user()?->isAdmin() === true)->using(function (DnsRecord $record, array $data): DnsRecord {
                return $this->updateRecord($record, $data);
            }),
            DeleteAction::make()->visible(fn (DnsRecord $record): bool => $record->type !== 'NS' || auth()->user()?->isAdmin() === true)->using(function (DnsRecord $record): bool {
                return $this->deleteRecord($record);
            }),
        ])->toolbarActions([
            BulkActionGroup::make([
                BulkAction::make('delete')->label('Delete selected')->color('danger')->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        abort_if(
                            auth()->user()?->isAdmin() !== true && $records->contains(fn (DnsRecord $record): bool => $record->type === 'NS'),
                            403,
                            'Only administrators can manage delegated NS records.',
                        );
                        $domain = $this->getOwnerRecord();
                        DB::transaction(function () use ($domain, $records): void {
                            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
                            $ids = $records->pluck('id');
                            $deleted = $locked->dnsRecords()->whereIn('id', $ids)->delete();
                            if ($deleted > 0) {
                                $locked->forceFill(['revision' => $locked->revision + 1])->save();
                                AuditLog::record(auth()->user(), 'dns.records_bulk_deleted', $locked, ['revision' => $locked->revision, 'records' => $deleted], request()->ip());
                            }
                        });
                        $this->reconcileIfActive($domain->refresh());
                    }),
            ]),
        ])->defaultSort('id');
    }

    private function createRecord(array $input): DnsRecord
    {
        $domain = $this->getOwnerRecord();
        $record = DB::transaction(function () use ($domain, $input): DnsRecord {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $data = DnsRecordData::validate($input, $locked->name);
            abort_if($data['type'] === 'NS' && auth()->user()?->isAdmin() !== true, 403, 'Only administrators can manage delegated NS records.');
            $rows = $locked->dnsRecords()->lockForUpdate()->get()->map(fn (DnsRecord $item): array => $this->row($item))->push($data);
            DnsZoneValidator::assertValid($rows);
            $record = $locked->dnsRecords()->create($data);
            $this->changed($locked, 'dns.record_created', $record);

            return $record;
        });
        $this->reconcileIfActive($domain->refresh());

        return $record;
    }

    private function updateRecord(DnsRecord $record, array $input): DnsRecord
    {
        abort_if($record->type === 'NS' && auth()->user()?->isAdmin() !== true, 403, 'Only administrators can manage delegated NS records.');
        $domain = $this->getOwnerRecord();
        DB::transaction(function () use ($domain, $record, $input): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $data = DnsRecordData::validate($input, $locked->name);
            abort_if($data['type'] === 'NS' && auth()->user()?->isAdmin() !== true, 403, 'Only administrators can manage delegated NS records.');
            $rows = $locked->dnsRecords()->lockForUpdate()->whereKeyNot($record->id)->get()->map(fn (DnsRecord $item): array => $this->row($item))->push($data);
            DnsZoneValidator::assertValid($rows);
            $record->update($data);
            $this->changed($locked, 'dns.record_updated', $record);
        });
        $this->reconcileIfActive($domain->refresh());

        return $record->refresh();
    }

    private function deleteRecord(DnsRecord $record): bool
    {
        abort_if($record->type === 'NS' && auth()->user()?->isAdmin() !== true, 403, 'Only administrators can manage delegated NS records.');
        $domain = $this->getOwnerRecord();
        DB::transaction(function () use ($domain, $record): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $record->delete();
            $this->changed($locked, 'dns.record_deleted', $record);
        });
        $this->reconcileIfActive($domain->refresh());

        return true;
    }

    private function changed(Domain $domain, string $action, DnsRecord $record): void
    {
        $domain->forceFill(['revision' => $domain->revision + 1])->save();
        AuditLog::record(auth()->user(), $action, $record, ['domain_id' => $domain->id, 'revision' => $domain->revision], request()->ip());
    }

    private function reconcileIfActive(Domain $domain): void
    {
        if ($domain->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
            ReconcileDnsZone::dispatch($domain->id)->afterCommit();
        }
    }

    private function row(DnsRecord $record): array
    {
        return $record->only(['type', 'name', 'content', 'content_hash', 'ttl', 'priority', 'weight', 'port', 'mode']);
    }
}
