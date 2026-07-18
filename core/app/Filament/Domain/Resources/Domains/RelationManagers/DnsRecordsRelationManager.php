<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Enums\DomainLifecycleState;
use App\Jobs\DispatchOriginTest;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\Operation;
use App\Support\DnsRecordData;
use App\Support\DnsZoneValidator;
use App\Support\GeoDnsConfig;
use App\Support\GeoIpClassifier;
use App\Support\OriginData;
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
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            })->required()->live()->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                if (! in_array($state, ['A', 'AAAA', 'CNAME'], true) && $get('mode') === 'proxied') {
                    $set('mode', 'dns_only');
                }
            }),
            Select::make('mode')->options(function (Get $get): array {
                $options = ['dns_only' => 'DNS only'];
                if (in_array($get('type'), GeoDnsConfig::SUPPORTED_TYPES, true)) {
                    $options['geo_dns'] = 'Geo-DNS';
                }
                if (in_array($get('type'), ['A', 'AAAA', 'CNAME'], true)) {
                    $options['proxied'] = 'Proxied';
                }

                return $options;
            })
                ->default('dns_only')->required()->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    if ($state !== 'proxied') {
                        return;
                    }
                    $hostname = $this->ownerHostname((string) $get('name'));
                    if (blank($get('origin.host_header'))) {
                        $set('origin.host_header', $hostname);
                    }
                    if (blank($get('origin.sni'))) {
                        $set('origin.sni', $hostname);
                    }
                })
                ->helperText('Geo-DNS is shown for supported record types. Proxy is available for A, AAAA, and CNAME records.'),
            TextInput::make('name')->required()->default('@')->maxLength(253)->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set): void {
                    $hostname = $this->ownerHostname((string) $state);
                    $oldHostname = $this->ownerHostname((string) $old);
                    if (blank($get('origin.host_header')) || $get('origin.host_header') === $oldHostname) {
                        $set('origin.host_header', $hostname);
                    }
                    if (blank($get('origin.sni')) || $get('origin.sni') === $oldHostname) {
                        $set('origin.sni', $hostname);
                    }
                })
                ->helperText(fn (Get $get): ?string => $get('type') === 'CNAME'
                    ? 'A CNAME must be the only DNS record at this hostname. Use a new hostname if A, AAAA, NS, or another record already exists there.'
                    : null),
            TextInput::make('content')->required(fn ($get): bool => $get('mode') === 'dns_only')->maxLength(4096)
                ->visible(fn ($get): bool => $get('mode') === 'dns_only'),
            TextInput::make('origin.host')->label('Origin server hostname or IP')->required(fn ($get): bool => $get('mode') === 'proxied')->visible(fn ($get): bool => $get('mode') === 'proxied')
                ->helperText('For cPanel/shared hosting, enter the server hostname or dedicated origin IP. Do not enter an address that routes back to this CDN.'),
            Select::make('origin.scheme')->options(['https' => 'HTTPS', 'http' => 'HTTP'])->default('https')->live()->required(fn ($get): bool => $get('mode') === 'proxied')->visible(fn ($get): bool => $get('mode') === 'proxied')
                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    if ($state === 'https') {
                        $set('origin.port', 443);
                        $set('origin.verify_tls', true);
                        if (blank($get('origin.sni'))) {
                            $set('origin.sni', $this->ownerHostname((string) $get('name')));
                        }
                    } elseif ($state === 'http') {
                        $set('origin.port', 80);
                        $set('origin.verify_tls', false);
                        $set('origin.sni', null);
                    }
                }),
            TextInput::make('origin.port')->numeric()->default(443)->disabled()->dehydrated()
                ->required(fn ($get): bool => $get('mode') === 'proxied')->visible(fn ($get): bool => $get('mode') === 'proxied')
                ->helperText('Managed by the scheme: HTTP uses 80 and HTTPS uses 443. Advanced custom ports remain available through the API.'),
            TextInput::make('origin.host_header')->label('Origin Host header')->required(fn ($get): bool => $get('mode') === 'proxied')->visible(fn ($get): bool => $get('mode') === 'proxied'),
            TextInput::make('origin.sni')->label('TLS SNI')->required(fn ($get): bool => $get('mode') === 'proxied' && $get('origin.scheme') === 'https' && (bool) $get('origin.verify_tls'))
                ->visible(fn ($get): bool => $get('mode') === 'proxied' && $get('origin.scheme') === 'https'),
            Toggle::make('origin.verify_tls')->label('Verify origin TLS')->default(true)
                ->visible(fn ($get): bool => $get('mode') === 'proxied' && $get('origin.scheme') === 'https'),
            TextInput::make('origin.connect_timeout_ms')->numeric()->default(2000)->minValue(100)->maxValue(10000)->visible(fn ($get): bool => $get('mode') === 'proxied'),
            TextInput::make('origin.response_timeout_ms')->numeric()->default(30000)->minValue(500)->maxValue(60000)->visible(fn ($get): bool => $get('mode') === 'proxied'),
            TextInput::make('origin.retry_count')->numeric()->default(1)->minValue(0)->maxValue(2)->visible(fn ($get): bool => $get('mode') === 'proxied'),
            Toggle::make('origin.websocket')->label('Allow WebSocket upgrade')->default(false)->visible(fn ($get): bool => $get('mode') === 'proxied'),
            Toggle::make('origin.health_check.enabled')->label('Enable scheduled origin health check')->default(false)->visible(fn ($get): bool => $get('mode') === 'proxied'),
            TextInput::make('origin.health_check.path')->label('Health-check path')->default('/')->maxLength(1024)->visible(fn ($get): bool => $get('mode') === 'proxied' && $get('origin.health_check.enabled')),
            TextInput::make('origin.health_check.interval_seconds')->label('Health-check interval (seconds)')->numeric()->default(300)->minValue(60)->maxValue(86400)->visible(fn ($get): bool => $get('mode') === 'proxied' && $get('origin.health_check.enabled')),
            TagsInput::make('geo_default')->label('Default answers')
                ->visible(fn ($get): bool => $get('mode') === 'geo_dns')
                ->required(fn ($get): bool => $get('mode') === 'geo_dns')
                ->nestedRecursiveRules(['string', 'max:4096']),
            Repeater::make('geo_countries')->label('Country overrides')->maxItems(GeoDnsConfig::MAX_COUNTRIES)
                ->visible(fn ($get): bool => $get('mode') === 'geo_dns')->schema([
                    Select::make('code')->options(array_combine(GeoDnsConfig::countryCodes(), GeoDnsConfig::countryCodes()))->searchable()->required(),
                    TagsInput::make('targets')->required()->nestedRecursiveRules(['string', 'max:4096']),
                ])->columns(2),
            Repeater::make('geo_continents')->label('Continent overrides')->maxItems(GeoDnsConfig::MAX_CONTINENTS)
                ->visible(fn ($get): bool => $get('mode') === 'geo_dns')->schema([
                    Select::make('code')->options(array_combine(GeoDnsConfig::CONTINENTS, GeoDnsConfig::CONTINENTS))->required(),
                    TagsInput::make('targets')->required()->nestedRecursiveRules(['string', 'max:4096']),
                ])->columns(2)->helperText('Country overrides win over continent overrides. Each answer set is limited to 8 type-valid values.'),
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
            TextColumn::make('origin_health.status')->label('Origin')->placeholder('Not tested')->badge(),
        ])->headerActions([
            CreateAction::make()->createAnother(false)->using(function (array $data): DnsRecord {
                return $this->createRecord($data);
            }),
        ])->recordActions([
            Action::make('testOrigin')->label('Test origin')->icon('heroicon-o-signal')
                ->visible(fn (DnsRecord $record): bool => $record->mode === 'proxied')
                ->action(function (DnsRecord $record): void {
                    $operation = Operation::query()->create([
                        'id' => (string) Str::uuid(), 'type' => 'edge.origin_test', 'status' => 'pending', 'actor_id' => auth()->id(),
                        'input' => ['domain_id' => $record->domain_id, 'record_id' => $record->id, 'addresses' => OriginData::resolveAndValidate($record->origin['host']), 'edge_ids' => []],
                    ]);
                    DispatchOriginTest::dispatch($operation->id)->afterCommit();
                    Notification::make()->info()->title('Origin test queued')->body("Operation {$operation->id} will run on qualified edges.")->send();
                }),
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
        $input = $this->normalizeOriginForm($input);
        $domain = $this->getOwnerRecord();
        $record = DB::transaction(function () use ($domain, $input): DnsRecord {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $data = $this->validateRecordForForm($input, $locked->name);
            abort_if($data['type'] === 'NS' && auth()->user()?->isAdmin() !== true, 403, 'Only administrators can manage delegated NS records.');
            $rows = $locked->dnsRecords()->lockForUpdate()->get()->map(fn (DnsRecord $item): array => $this->row($item))->push($data);
            $this->assertZoneValidForForm($rows);
            $record = $locked->dnsRecords()->create($data);
            $this->changed($locked, 'dns.record_created', $record);

            return $record;
        });
        $this->reconcileIfActive($domain->refresh());
        $this->notifyRecordSaved($record, 'created');

        return $record;
    }

    private function updateRecord(DnsRecord $record, array $input): DnsRecord
    {
        $input = $this->normalizeGeoForm($input);
        $input = $this->normalizeOriginForm($input);
        abort_if($record->type === 'NS' && auth()->user()?->isAdmin() !== true, 403, 'Only administrators can manage delegated NS records.');
        $domain = $this->getOwnerRecord();
        DB::transaction(function () use ($domain, $record, $input): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $data = $this->validateRecordForForm($input, $locked->name);
            abort_if($data['type'] === 'NS' && auth()->user()?->isAdmin() !== true, 403, 'Only administrators can manage delegated NS records.');
            $rows = $locked->dnsRecords()->lockForUpdate()->whereKeyNot($record->id)->get()->map(fn (DnsRecord $item): array => $this->row($item))->push($data);
            $this->assertZoneValidForForm($rows);
            $record->update($data);
            $this->changed($locked, 'dns.record_updated', $record);
        });
        $this->reconcileIfActive($domain->refresh());
        $this->notifyRecordSaved($record->refresh(), 'updated');

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
        Operation::coalesceDomain('edge.domain_reconcile', $domain->id, auth()->id());
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
        if ($domain->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
            ReconcileDnsZone::dispatch($domain->id)->afterCommit();
        }
    }

    private function row(DnsRecord $record): array
    {
        return $record->only(['type', 'name', 'content', 'content_hash', 'ttl', 'priority', 'weight', 'port', 'mode', 'origin']);
    }

    private function normalizeGeoForm(array $input): array
    {
        if (($input['mode'] ?? 'dns_only') !== 'geo_dns') {
            unset($input['geo_default'], $input['geo_countries'], $input['geo_continents']);

            return $input;
        }
        foreach (['geo_countries', 'geo_continents'] as $field) {
            if (collect($input[$field] ?? [])->pluck('code')->duplicates()->isNotEmpty()) {
                throw $this->formValidationException([$field => 'Geographic override codes cannot be duplicated.']);
            }
        }
        $map = fn (string $field): array => collect($input[$field] ?? [])->mapWithKeys(fn (array $row): array => [$row['code'] => $row['targets']])->all();
        $input['geo'] = ['default' => $input['geo_default'] ?? [], 'countries' => $map('geo_countries'), 'continents' => $map('geo_continents')];
        unset($input['geo_default'], $input['geo_countries'], $input['geo_continents']);

        return $input;
    }

    private function ownerHostname(string $owner): string
    {
        try {
            return DnsRecordData::normalizeOwner($owner === '' ? '@' : $owner, $this->getOwnerRecord()->name);
        } catch (\InvalidArgumentException) {
            return $this->getOwnerRecord()->name;
        }
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

    private function normalizeOriginForm(array $input): array
    {
        if (($input['mode'] ?? 'dns_only') !== 'proxied' || ! is_array($input['origin'] ?? null)) {
            return $input;
        }

        if (($input['origin']['scheme'] ?? 'https') === 'http') {
            $input['origin']['port'] = 80;
            $input['origin']['verify_tls'] = false;
            $input['origin']['sni'] = null;
        } else {
            $input['origin']['port'] = 443;
        }
        if (! (bool) data_get($input, 'origin.health_check.enabled', false)) {
            $input['origin']['health_check'] = null;
        }

        return $input;
    }

    private function assertZoneValidForForm(Collection $rows): void
    {
        try {
            DnsZoneValidator::assertValid($rows);
        } catch (ValidationException $exception) {
            $zoneErrors = $exception->errors()['records'] ?? null;
            if ($zoneErrors !== null) {
                throw $this->formValidationException(['name' => $zoneErrors]);
            }

            throw $exception;
        }
    }

    private function notifyRecordSaved(DnsRecord $record, string $verb): void
    {
        if ($record->mode !== 'proxied') {
            Notification::make()->success()->title("DNS record {$verb}")
                ->body('Desired state was saved and DNS reconciliation was queued when the domain is active.')->send();

            return;
        }

        $readyEdgeExists = Edge::query()->readyForTraffic()->exists();
        Notification::make()
            ->title($readyEdgeExists ? "Proxied record {$verb}" : 'Proxied desired state saved')
            ->body($readyEdgeExists
                ? 'The latest desired revision is queued for edge deployment.'
                : 'No ready edge is connected yet. The origin and proxy policy are safely stored in PostgreSQL and will deploy after an edge enrolls and reports a ready listener.')
            ->color($readyEdgeExists ? 'success' : 'warning')
            ->send();
    }

    private function validateRecordForForm(array $input, string $zone): array
    {
        try {
            return DnsRecordData::validate($input, $zone);
        } catch (ValidationException $exception) {
            $originFields = ['host', 'port', 'scheme', 'host_header', 'sni', 'verify_tls', 'connect_timeout_ms', 'response_timeout_ms', 'retry_count', 'websocket', 'health_check'];
            $messages = [];
            foreach ($exception->errors() as $field => $errors) {
                $root = explode('.', $field, 2)[0];
                $visibleField = match (true) {
                    in_array($root, $originFields, true) => 'origin.'.$field,
                    $root === 'default' => 'geo_default',
                    $root === 'countries' => 'geo_countries',
                    $root === 'continents' => 'geo_continents',
                    default => $field,
                };
                $messages[$visibleField] = $errors;
            }

            throw $this->formValidationException($messages);
        }
    }

    private function formValidationException(array $messages): ValidationException
    {
        $statePath = $this->getMountedActionSchema()?->getStatePath();
        if (blank($statePath)) {
            return ValidationException::withMessages($messages);
        }

        return ValidationException::withMessages(collect($messages)
            ->mapWithKeys(fn (mixed $errors, string $field): array => ["{$statePath}.{$field}" => $errors])
            ->all());
    }
}
