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
use App\Support\GeoDnsConfig;
use App\Support\GeoIpClassifier;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            TagsInput::make('geo_default')->label('Default answers')
                ->visible(fn ($get): bool => $get('mode') === 'geo_dns')
                ->required(fn ($get): bool => $get('mode') === 'geo_dns')
                ->nestedRecursiveRules(['string', 'max:45']),
            Repeater::make('geo_countries')->label('Country overrides')->maxItems(GeoDnsConfig::MAX_COUNTRIES)
                ->visible(fn ($get): bool => $get('mode') === 'geo_dns')->schema([
                    Select::make('code')->options(array_combine(GeoDnsConfig::countryCodes(), GeoDnsConfig::countryCodes()))->searchable()->required(),
                    TagsInput::make('targets')->required()->nestedRecursiveRules(['string', 'max:45']),
                ])->columns(2),
            Repeater::make('geo_continents')->label('Continent overrides')->maxItems(GeoDnsConfig::MAX_CONTINENTS)
                ->visible(fn ($get): bool => $get('mode') === 'geo_dns')->schema([
                    Select::make('code')->options(array_combine(GeoDnsConfig::CONTINENTS, GeoDnsConfig::CONTINENTS))->required(),
                    TagsInput::make('targets')->required()->nestedRecursiveRules(['string', 'max:45']),
                ])->columns(2)->helperText('Country overrides win over continent overrides. Each target set is limited to 8 addresses.'),
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
            Action::make('previewGeo')->label('Preview')->visible(fn (DnsRecord $record): bool => $record->mode === 'geo_dns')
                ->schema([TextInput::make('ip')->ip()->required()])
                ->action(function (DnsRecord $record, array $data): void {
                    $geo = app(GeoIpClassifier::class)->classify($data['ip']);
                    $targets = GeoDnsConfig::select($record->geo_config, $geo['country'], $geo['continent']);
                    Notification::make()->info()->title('Geo-DNS preview')
                        ->body(sprintf('%s / %s → %s. Runtime uses trusted ECS, otherwise the recursive resolver.', $geo['country'] ?? 'unknown', $geo['continent'] ?? 'unknown', implode(', ', $targets)))->send();
                }),
            EditAction::make()->mutateRecordDataUsing(fn (array $data, DnsRecord $record): array => $this->hydrateGeoForm($data, $record))
                ->visible(fn (DnsRecord $record): bool => $record->type !== 'NS' || auth()->user()?->isAdmin() === true)->using(function (DnsRecord $record, array $data): DnsRecord {
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
        $input = $this->normalizeGeoForm($input);
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
        $input = $this->normalizeGeoForm($input);
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

    private function normalizeGeoForm(array $input): array
    {
        if (($input['mode'] ?? 'dns_only') !== 'geo_dns') {
            unset($input['geo_default'], $input['geo_countries'], $input['geo_continents']);

            return $input;
        }
        foreach (['geo_countries', 'geo_continents'] as $field) {
            if (collect($input[$field] ?? [])->pluck('code')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([$field => 'Geographic override codes cannot be duplicated.']);
            }
        }
        $map = fn (string $field): array => collect($input[$field] ?? [])->mapWithKeys(fn (array $row): array => [$row['code'] => $row['targets']])->all();
        $input['geo'] = ['default' => $input['geo_default'] ?? [], 'countries' => $map('geo_countries'), 'continents' => $map('geo_continents')];
        unset($input['geo_default'], $input['geo_countries'], $input['geo_continents']);

        return $input;
    }

    private function hydrateGeoForm(array $data, DnsRecord $record): array
    {
        if ($record->mode !== 'geo_dns') {
            return $data;
        }
        $data['geo_default'] = $record->geo_config['default'];
        $data['geo_countries'] = collect($record->geo_config['countries'])->map(fn (array $targets, string $code): array => compact('code', 'targets'))->values()->all();
        $data['geo_continents'] = collect($record->geo_config['continents'])->map(fn (array $targets, string $code): array => compact('code', 'targets'))->values()->all();

        return $data;
    }
}
