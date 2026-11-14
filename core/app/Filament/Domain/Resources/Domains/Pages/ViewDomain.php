<?php

namespace App\Filament\Domain\Resources\Domains\Pages;

use App\Actions\ChangeAutomaticSecurityState;
use App\Actions\DispatchCachePurge;
use App\Actions\EndIncidentProfile;
use App\Actions\StartIncidentProfile;
use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Filament\Domain\Resources\Domains\Widgets\DomainIncidentBanner;
use App\Filament\Domain\Resources\Domains\Widgets\DomainKpiStrip;
use App\Filament\Domain\Resources\Domains\Widgets\DomainNeedsAttention;
use App\Http\Controllers\AiProtectionController;
use App\Http\Controllers\ApiSchemaController;
use App\Http\Controllers\ClientSecurityController;
use App\Http\Controllers\CredentialCheckController;
use App\Http\Controllers\DetectionSettingsController;
use App\Http\Controllers\DnsDeploymentController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\SecurityAlertController;
use App\Http\Controllers\SensitiveDataController;
use App\Http\Controllers\WafController;
use App\Jobs\EnsureManagedCertificates;
use App\Jobs\ImportDnsZone;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgePool;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Support\ApiSchemaData;
use App\Support\BindZone;
use App\Support\CachePolicy;
use App\Support\CredentialCheckData;
use App\Support\DnsZoneImporter;
use App\Support\DomainNameserverVerification;
use App\Support\FilamentHelp;
use App\Support\IncidentProfile;
use App\Support\ManagedWafTuning;
use App\Support\PlatformSettings;
use App\Support\ProxyRevisionRollback;
use App\Support\SecurityAlertData;
use App\Support\SecurityConfig;
use App\Support\SensitiveDataCheckData;
use App\Support\UploadedCertificate;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    /**
     * Page loads keep the assignment-scoped lookup (not found for unassigned domains). A Livewire request on an
     * already-open page re-resolves the record, so a user revoked mid-session is authorized by policy instead
     * and receives 403, matching the API.
     */
    protected function resolveRecord(int|string $key): Model
    {
        if (! request()->hasHeader('X-Livewire')) {
            return parent::resolveRecord($key);
        }

        $domain = Domain::query()->findOrFail($key);
        Gate::authorize('view', $domain);

        return $domain;
    }

    /** Every action re-reads the domain and tells the header widgets and the open tab, so no change needs a reload. */
    public function callMountedAction(array $arguments = []): mixed
    {
        $result = parent::callMountedAction($arguments);
        $this->refreshDomain(includeTabs: true);

        return $result;
    }

    /** @return array<int, string> rule id => "942100 SQL Injection Attack Detected via libinjection" */
    private static function wafRuleOptions(): array
    {
        return collect(ManagedWafTuning::catalog()['rules'])->mapWithKeys(fn (array $rule): array => [$rule['id'] => $rule['id'].' '.$rule['message']])->all();
    }

    /** A tab or the polling KPI strip saw the domain change: re-read it and refresh the other views. */
    #[On('domain-updated')]
    public function refreshDomain(bool $includeTabs = false): void
    {
        try {
            $this->record->refresh();
        } catch (ModelNotFoundException) {
            return;
        }
        $this->dispatch('domain-refreshed', tabs: $includeTabs);
    }

    /** A header widget (the under attack banner) asks the page to open one of its actions. Only listed actions can be opened. */
    #[On('open-domain-action')]
    public function openDomainAction(string $name): void
    {
        if (in_array($name, ['endIncident'], true)) {
            $this->mountAction($name);
        }
    }

    public function getTitle(): string
    {
        return $this->record->display_name ?: $this->record->name;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }

    public function getHeading(): string|Htmlable
    {
        $state = $this->record->lifecycle_state->value;
        $tone = $state === 'active' ? 'success' : 'warning';

        return new HtmlString(e($this->getTitle()).' '.Blade::render('<x-ui.status-pill tone="'.$tone.'" data-testid="domain-status">{{ $label }}</x-ui.status-pill>', ['label' => str($state)->replace('_', ' ')->headline()->toString()]));
    }

    public function getSubheading(): ?string
    {
        return $this->record->display_name && $this->record->display_name !== $this->record->name
            ? $this->record->name
            : null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [DomainKpiStrip::class, DomainIncidentBanner::class, DomainNeedsAttention::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    private function hasValidCustomCertificate(): bool
    {
        return $this->domain()->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->where('expires_at', '>', now())->exists();
    }

    private function domain(): Domain
    {
        assert($this->record instanceof Domain);

        return $this->record;
    }

    /** @return array<string, Action> every action of the page by name, shown in the header or next to the facts it changes */
    private function domainActions(): array
    {
        $actions = [
            Action::make('reconcileDns')->label('Reconcile authoritative DNS')->icon('phosphor-arrows-clockwise')
                ->visible(fn (): bool => $this->record->lifecycle_state === DomainLifecycleState::Active)
                ->action(function (): void {
                    $response = app(DnsDeploymentController::class)->reconcile(request(), $this->record);
                    $operation = $response->getData(true)['data'];
                    Notification::make()->info()->title('DNS reconciliation queued')
                        ->body("Operation {$operation['id']} will preserve the previous valid zone until activation succeeds.")->send();
                }),
            Action::make('tlsMode')->label('TLS mode')->icon('phosphor-lock')->schema([
                Select::make('mode')->label(FilamentHelp::label('Mode', 'Managed: the platform issues and renews the certificate. Custom: your uploaded certificate is served. Disabled: no HTTPS for this domain.'))
                    ->options(fn (): array => ['managed' => 'Managed', 'custom' => $this->hasValidCustomCertificate() ? 'Custom' : 'Custom (upload a certificate first)', 'disabled' => 'Disabled'])
                    ->disableOptionWhen(fn (string $value): bool => $value === 'custom' && ! $this->hasValidCustomCertificate())->required(),
            ])->fillForm(fn (): array => ['mode' => $this->record->tls_mode])
                ->action(function (array $data): void {
                    DB::transaction(function () use ($data): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $certificateId = $data['mode'] === 'custom'
                            ? $domain->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->value('id')
                            : ($data['mode'] === 'managed' ? $domain->tlsCertificates()->where('kind', 'managed')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->value('id') : $domain->active_tls_certificate_id);
                        if ($data['mode'] === 'custom' && $certificateId === null) {
                            throw ValidationException::withMessages([
                                'mountedActions.0.data.mode' => 'Upload a valid custom certificate before selecting custom mode.',
                            ]);
                        }
                        $domain->update(['tls_mode' => $data['mode'], 'active_tls_certificate_id' => $certificateId, 'revision' => $domain->revision + 1]);
                        AuditLog::record(auth()->user(), 'tls.mode_updated', $domain, ['mode' => $data['mode'], 'revision' => $domain->revision], request()->ip());
                    });
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    if ($data['mode'] === 'managed') {
                        EnsureManagedCertificates::dispatch($this->record->id)->afterCommit();
                    }
                }),
            Action::make('renewManagedCertificate')->label('Renew managed certificate')->icon('phosphor-arrows-clockwise')
                ->visible(fn (): bool => $this->record->tls_mode === 'managed' && $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(function (): void {
                    $operation = Operation::query()->create([
                        'actor_id' => auth()->id(), 'type' => 'tls.managed_renew', 'status' => 'pending',
                        'input' => ['domain_id' => $this->record->id],
                    ]);
                    EnsureManagedCertificates::dispatch($this->record->id)->afterCommit();
                    Notification::make()->info()->title('Managed renewal queued')->body("Operation {$operation->id} will reuse valid coverage or queue a bounded ACME order.")->send();
                }),
            Action::make('reissueManagedCertificate')->icon('phosphor-arrows-clockwise')->label('Reissue managed certificate')->color('warning')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->tls_mode === 'managed' && $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(function (): void {
                    $operation = Operation::query()->create([
                        'actor_id' => auth()->id(), 'type' => 'tls.managed_reissue', 'status' => 'pending',
                        'input' => ['domain_id' => $this->record->id],
                    ]);
                    EnsureManagedCertificates::dispatch($this->record->id, true)->afterCommit();
                    Notification::make()->warning()->title('Managed reissue queued')->body("Operation {$operation->id} will create a new order within the global CA budget.")->send();
                }),
            Action::make('uploadCertificate')->label('Upload custom certificate')->icon('phosphor-file-arrow-up')->schema([
                Textarea::make('certificate')->label(FilamentHelp::label('Leaf certificate PEM', 'The certificate for this domain itself, not the chain.'))->rows(8)->maxLength(16384)->required()->placeholder('-----BEGIN CERTIFICATE-----'),
                Textarea::make('chain')->label(FilamentHelp::label('Intermediate certificates PEM', 'The certificates between yours and the root, in order. Leave the root out.'))->rows(8)->maxLength(65536)->placeholder('-----BEGIN CERTIFICATE-----'),
                Textarea::make('private_key')->label(FilamentHelp::label('Private key PEM', 'Stored encrypted and never shown again.'))->rows(8)->maxLength(16384)->required()->placeholder('-----BEGIN PRIVATE KEY-----'),
            ])->action(function (array $data): void {
                $expectedRevision = (int) $this->record->revision;
                $validated = UploadedCertificate::validate($this->record, $data['certificate'], $data['chain'] ?? '', $data['private_key']);
                DB::transaction(function () use ($validated, $expectedRevision): void {
                    $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                    if ((int) $domain->revision !== $expectedRevision) {
                        throw ValidationException::withMessages([
                            'mountedActions.0.data.certificate' => 'The domain changed while validating the certificate. Reload and retry the upload.',
                        ]);
                    }
                    $certificate = $domain->tlsCertificates()->where('kind', 'custom')->where('fingerprint_sha256', $validated['fingerprint_sha256'])->first();
                    if ($certificate === null) {
                        $certificate = $domain->tlsCertificates()->create([
                            'kind' => 'custom', 'status' => 'active', 'certificate_pem' => $validated['certificate_pem'],
                            'chain_pem' => $validated['chain_pem'], 'private_key_ciphertext' => $validated['private_key'],
                            'names' => $validated['names'], 'fingerprint_sha256' => $validated['fingerprint_sha256'],
                            'not_before' => $validated['not_before'], 'expires_at' => $validated['expires_at'], 'activated_at' => now(),
                        ]);
                    } else {
                        $certificate->update([
                            'chain_pem' => $validated['chain_pem'],
                            'status' => 'active', 'activated_at' => $certificate->activated_at ?? now(), 'last_error' => null,
                        ]);
                    }
                    $domain->tlsCertificates()->where('kind', 'custom')->where('id', '!=', $certificate->id)->where('status', 'active')->update(['status' => 'superseded']);
                    $domain->update(['tls_mode' => 'custom', 'active_tls_certificate_id' => $certificate->id, 'revision' => $domain->revision + 1]);
                    AuditLog::record(auth()->user(), 'tls.custom_uploaded', $certificate, ['domain_id' => $domain->id, 'revision' => $domain->revision, 'fingerprint' => $certificate->fingerprint_sha256], request()->ip());
                });
                Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                Notification::make()->success()->title('Custom certificate accepted')->body('The encrypted key and validated chain are queued for edge delivery.')->send();
            }),
            Action::make('deleteCustomCertificate')->icon('phosphor-trash')->label('Remove custom certificate')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->exists())
                ->action(function (): void {
                    DB::transaction(function (): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $domain->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->update(['status' => 'revoked']);
                        $managed = $domain->tlsCertificates()->where('kind', 'managed')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->first();
                        $domain->update(['tls_mode' => 'managed', 'active_tls_certificate_id' => $managed?->id, 'revision' => $domain->revision + 1]);
                        AuditLog::record(auth()->user(), 'tls.custom_deleted', $domain, ['revision' => $domain->revision], request()->ip());
                    });
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    EnsureManagedCertificates::dispatch($this->record->id)->afterCommit();
                }),
            Action::make('cacheSettings')->label('Cache settings')->icon('phosphor-database')->extraAttributes(['data-testid' => 'cache-settings-action'])->schema([
                Section::make('Lifetime')->schema([
                    Toggle::make('enabled')->label(FilamentHelp::label('Cache enabled', 'Turn off to send every request to the origin.'))->required(),
                    TextInput::make('edge_ttl_seconds')->label(FilamentHelp::label('Edge TTL (seconds)', 'How long the edge keeps a response before asking the origin again.'))->numeric()->minValue(0)->maxValue(31536000)->placeholder('3600')->required(),
                    TextInput::make('browser_ttl_seconds')->label(FilamentHelp::label('Browser TTL (seconds)', 'How long visitors keep a response in their own browser.'))->numeric()->minValue(0)->maxValue(31536000)->placeholder('300')->required(),
                    Select::make('maximum_object_bytes')->label(FilamentHelp::label('Maximum object size', 'Larger responses are streamed and never stored.'))->options([
                        1048576 => '1 MiB', 10485760 => '10 MiB', 104857600 => '100 MiB', 536870912 => '512 MiB', 1073741824 => '1 GiB',
                    ])->placeholder('100 MiB')->required(),
                    Toggle::make('respect_origin_headers')->label(FilamentHelp::label('Respect origin cache headers', 'When on, the origin\'s Cache-Control or Expires sets the edge lifetime. A path rule with a TTL still wins.'))->required(),
                ]),
                Section::make('Request matching')->schema([
                    Select::make('query_policy')->label(FilamentHelp::label('Query policy', 'Which query parameters make two URLs different cache entries.'))->options([
                        'include_all' => 'Include all', 'ignore_all' => 'Ignore all',
                        'include_selected' => 'Include selected', 'ignore_selected' => 'Ignore selected',
                    ])->placeholder('Include all')->required(),
                    TagsInput::make('query_parameters')->label(FilamentHelp::label('Selected query parameter names', 'Used by the two "selected" policies. Type a name and press Enter.'))->placeholder('page')->rules(['array', 'max:32']),
                    Select::make('cookie_presets')->label(FilamentHelp::label('Add analytics cookies', 'Adds the usual cookie names of a tool to the ignored list below, so visitors carrying them are still served from the cache.'))
                        ->native()->dehydrated(false)->placeholder('Choose a tool')->extraAttributes(['data-testid' => 'cache-cookie-presets'])
                        ->options(array_map(fn (array $preset): string => $preset['label'], CachePolicy::COOKIE_PRESETS))
                        ->live()->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            if (! isset(CachePolicy::COOKIE_PRESETS[$state])) {
                                return;
                            }
                            $set('ignore_cookie_names', collect($get('ignore_cookie_names') ?? [])
                                ->merge(CachePolicy::COOKIE_PRESETS[$state]['names'])->unique()->take(32)->values()->all());
                            $set('cookie_presets', null);
                        }),
                    TagsInput::make('ignore_cookie_names')->label(FilamentHelp::label('Ignored cookie names', 'A request with any other cookie bypasses the cache. A name ending in * matches every cookie that starts with it.'))->placeholder('_ga_*')->rules(['array', 'max:32'])->nestedRecursiveRules(['regex:/^[A-Za-z0-9_.-]{1,63}\*?$/']),
                    Repeater::make('path_rules')->label(FilamentHelp::label('Path rules', 'The first rule that matches the path wins. Bypass skips the cache. TTL sets the edge lifetime for that path and replaces the origin\'s own lifetime.'))
                        ->maxItems(CachePolicy::MAX_PATH_RULES)->addActionLabel('Add path rule')->defaultItems(0)->columns(['default' => 1, 'md' => 4])->schema([
                            Select::make('match')->label('Match')->options(['prefix' => 'Starts with', 'suffix' => 'Ends with'])->default('prefix')->native()->required(),
                            TextInput::make('value')->label('Path')->placeholder('/static/')->maxLength(128)->regex('/^[A-Za-z0-9\/_.~%-]{1,128}$/')
                                ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if ($get('match') === 'prefix' && is_string($value) && ! str_starts_with($value, '/')) {
                                        $fail('A path prefix must start with /.');
                                    }
                                })->required(),
                            Select::make('action')->label('Action')->options(['bypass' => 'Bypass cache', 'ttl' => 'Set edge TTL'])->default('ttl')->native()->required(),
                            TextInput::make('ttl_seconds')->label(FilamentHelp::label('TTL (seconds)', 'Used only when the action is Set edge TTL. Ignored for Bypass cache.'))->numeric()->minValue(1)->maxValue(31536000)->placeholder('86400')
                                ->requiredIf('action', 'ttl'),
                        ]),
                ]),
                Section::make('Advanced')->collapsed()->schema([
                    KeyValue::make('status_ttl_seconds')->label(FilamentHelp::label('Status TTLs', 'Edge lifetime per response status. 0 means that status is never stored.'))->keyLabel('Approved status')->valueLabel('TTL seconds')->keyPlaceholder('404')->valuePlaceholder('60')->reorderable(false),
                    TextInput::make('admission_requests')->label(FilamentHelp::label('Requests before admission', 'How many requests for one URL must arrive before it is stored. 2 keeps one-off URLs out of the cache.'))->numeric()->minValue(1)->maxValue(10)->placeholder('2')->required(),
                    TextInput::make('stale_if_error_seconds')->label(FilamentHelp::label('Stale-if-error (seconds)', 'How long an expired copy may be served while the origin is failing.'))->numeric()->minValue(0)->maxValue(86400)->placeholder('60')->required(),
                    TextInput::make('stale_while_revalidate_seconds')->label(FilamentHelp::label('Stale-while-revalidate (seconds)', 'How long an expired copy may be served while one request refreshes it.'))->numeric()->minValue(0)->maxValue(86400)->placeholder('30')->required(),
                    Select::make('mode')->label(FilamentHelp::label('Mode', 'Normal fetches from the origin on a miss. Cache only and stale only never reach the origin.'))->options(['normal' => 'Normal', 'cache_only' => 'Cache only', 'stale_only' => 'Stale only'])->placeholder('Normal')->required(),
                    TextInput::make('maximum_variants_per_resource')->label(FilamentHelp::label('Maximum variants/resource/minute', 'Caps how many different versions of one URL are stored per minute.'))->numeric()->minValue(1)->maxValue(128)->placeholder('32')->required(),
                ]),
            ])->fillForm(fn (): array => CachePolicy::normalize($this->record->cache_settings))
                ->action(function (array $data): void {
                    $settings = [
                        'enabled' => (bool) $data['enabled'], 'edge_ttl_seconds' => (int) $data['edge_ttl_seconds'],
                        'browser_ttl_seconds' => (int) $data['browser_ttl_seconds'], 'maximum_object_bytes' => (int) $data['maximum_object_bytes'],
                        'respect_origin_headers' => (bool) $data['respect_origin_headers'], 'query_policy' => $data['query_policy'],
                        'query_parameters' => array_values($data['query_parameters'] ?? []),
                        'ignore_cookie_names' => array_values($data['ignore_cookie_names'] ?? []),
                        'path_rules' => collect($data['path_rules'] ?? [])->map(fn (array $rule): array => [
                            'match' => $rule['match'], 'value' => $rule['value'], 'action' => $rule['action'],
                            'ttl_seconds' => $rule['action'] === 'ttl' ? (int) $rule['ttl_seconds'] : null,
                        ])->values()->all(),
                        'status_ttl_seconds' => $data['status_ttl_seconds'], 'admission_requests' => (int) $data['admission_requests'],
                        'stale_if_error_seconds' => (int) $data['stale_if_error_seconds'],
                        'stale_while_revalidate_seconds' => (int) $data['stale_while_revalidate_seconds'],
                        'mode' => $data['mode'], 'maximum_variants_per_resource' => (int) $data['maximum_variants_per_resource'],
                    ];
                    DB::transaction(function () use ($settings): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $domain->update(['cache_settings' => $settings, 'revision' => $domain->revision + 1]);
                        AuditLog::record(auth()->user(), 'cache.settings_updated', $domain, ['revision' => $domain->revision], request()->ip());
                    });
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    Notification::make()->success()->title('Cache settings saved')->body('The latest domain revision is queued for edge delivery.')->send();
                }),
            Action::make('developmentMode')->label('Enable development mode')->icon('phosphor-code')->schema([
                TextInput::make('duration_minutes')->label(FilamentHelp::label('Duration (minutes)', 'Every request skips the cache until then. It turns off by itself.'))->numeric()->default(30)->minValue(1)->maxValue(1440)->required()->placeholder('30'),
            ])->visible(fn (): bool => ! $this->record->cache_development_mode_until?->isFuture())
                ->action(function (array $data): void {
                    DB::transaction(function () use ($data): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $domain->update(['cache_development_mode_until' => now()->addMinutes((int) $data['duration_minutes']), 'revision' => $domain->revision + 1]);
                        AuditLog::record(auth()->user(), 'cache.development_mode_enabled', $domain, ['expires_at' => $domain->cache_development_mode_until?->toIso8601String()], request()->ip());
                    });
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    Notification::make()->warning()->title('Development mode enabled')->body('The cache bypass has an automatic visible expiry.')->send();
                }),
            Action::make('disableDevelopmentMode')->icon('phosphor-code')->label('Disable development mode')->color('warning')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->cache_development_mode_until?->isFuture() === true)
                ->action(function (): void {
                    DB::transaction(function (): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $domain->update(['cache_development_mode_until' => null, 'revision' => $domain->revision + 1]);
                        AuditLog::record(auth()->user(), 'cache.development_mode_disabled', $domain, [], request()->ip());
                    });
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                }),
            Action::make('purgeCache')->label('Purge cache')->icon('phosphor-trash')->color('warning')->modalSubmitActionLabel('Purge cache')->schema([
                Radio::make('type')->hiddenLabel()->options(['all' => 'Everything', 'urls' => 'Exact URLs'])
                    ->descriptions(['all' => 'Every cached object for this domain.', 'urls' => 'Up to 100 URLs, one per line.'])
                    ->default('all')->required()->live(),
                Textarea::make('urls')->label('URLs (one per line)')->placeholder('https://www.example.com/styles/app.css')->rows(8)->maxLength(131072)->visible(fn ($get): bool => $get('type') === 'urls')->required(fn ($get): bool => $get('type') === 'urls'),
            ])->action(function (array $data): void {
                $urls = $data['type'] === 'urls' ? preg_split('/\R/', trim((string) $data['urls']), flags: PREG_SPLIT_NO_EMPTY) : [];
                abort_if(count($urls) > 100, 422, 'At most 100 URLs may be purged at once.');
                $purge = DispatchCachePurge::execute($this->record, $data['type'], $urls, auth()->user(), request()->ip());
                Notification::make()->info()->title('Cache purge queued')->body("Purge {$purge->id} is visible in per-edge delivery state.")->send();
            }),
            Action::make('proxyDefaults')->label('Proxy defaults')->icon('phosphor-sliders-horizontal')->schema([
                Toggle::make('enabled')->label(FilamentHelp::label('Proxy enabled', 'Off makes the edge answer 503 for every proxied hostname of this domain. DNS-only records are not affected.'))->default(true)->required(),
                Toggle::make('redirect_https')->label(FilamentHelp::label('Redirect HTTP to HTTPS', 'Plain HTTP requests get a 301 to the same address over HTTPS.'))->default(false)->required(),
                Select::make('http_versions')->label(FilamentHelp::label('Allowed HTTP versions', 'Versions visitors may use to reach the edge. Keep both unless an old client needs only one.'))->multiple()->options(['1.1' => 'HTTP/1.1', '2' => 'HTTP/2'])->required()->maxItems(2)->placeholder('Choose versions'),
                TextInput::make('retry_count')->label(FilamentHelp::label('Origin retry count', 'How many times a failed request to the origin is tried again. Only safe methods are retried.'))->numeric()->default(0)->minValue(0)->maxValue(2)->required()->placeholder('0'),
            ])->fillForm(function (): array {
                return is_array($this->record->proxy_settings) ? $this->record->proxy_settings : ReconcileEdgeDomain::defaults();
            })->action(function (array $data): void {
                $settings = [
                    'enabled' => (bool) $data['enabled'], 'redirect_https' => (bool) $data['redirect_https'],
                    'http_versions' => array_values($data['http_versions']), 'retry_count' => (int) $data['retry_count'],
                    'maintenance' => $this->record->proxy_settings['maintenance'] ?? null,
                ];
                DB::transaction(function () use ($settings): void {
                    $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                    $domain->update(['proxy_settings' => $settings, 'revision' => $domain->revision + 1]);
                    AuditLog::record(auth()->user(), 'proxy.defaults_updated', $domain, ['revision' => $domain->revision], request()->ip());
                });
                $operation = Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                $proxiedCount = $this->record->dnsRecords()->where('mode', 'proxied')->count();
                $readyEdgeExists = Edge::query()->readyForTraffic()->exists();
                Notification::make()
                    ->title('Proxy defaults saved')
                    ->body(match (true) {
                        $proxiedCount === 0 => 'Only domain-wide defaults changed. Create or edit an A, AAAA, or CNAME record and select Proxied before traffic can use them.',
                        ! $readyEdgeExists => "Desired revision saved as operation {$operation->id}. No ready edge is connected, so deployment will wait for agent enrollment and a healthy listener.",
                        default => "Operation {$operation->id} is deploying the saved defaults to ready edges.",
                    })
                    ->color($proxiedCount > 0 && $readyEdgeExists ? 'success' : 'warning')
                    ->send();
            }),
            Action::make('securitySettings')->label('Security profile and limits')->icon('phosphor-shield-check')->schema([
                Select::make('profile')
                    ->label(fn (Get $get): string|\Illuminate\Support\HtmlString => FilamentHelp::label('Profile', SecurityConfig::profileDescription((string) $get('profile'))))
                    ->options(SecurityConfig::profileOptions())->required()->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === null || $state === SecurityConfig::MANUAL_PROFILE) {
                            return;
                        }
                        foreach (SecurityConfig::ceilingLimits($state) as $field => $value) {
                            $set("limits.$field", $value);
                        }
                    }),
                Select::make('quarantine_policy')->label(FilamentHelp::label('Automatic readiness policy', 'What the platform does when this domain draws unusual error or attack traffic. Manual only records it. Automatic restriction tightens the limits by itself and lifts them when traffic is quiet.'))->options([
                    'manual' => 'Manual', 'automatic' => 'Automatic restriction',
                    'automatic_with_admin_notification' => 'Automatic restriction with administrator notification',
                ])->required(),
                TagsInput::make('allowed_methods')->label(FilamentHelp::label('Allowed methods', 'Requests with any other HTTP method are refused with 405.'))->placeholder('GET')->suggestions(config('security.allowed_methods'))->required()->nestedRecursiveRules(['in:'.implode(',', config('security.allowed_methods'))]),
                TagsInput::make('trusted_proxy_cidrs')->label(FilamentHelp::label('Trusted L4 proxy CIDRs', 'Leave empty unless requests arrive only through an approved balancer that overwrites X-Forwarded-For.')),
                ...collect(array_keys(config('security.profiles.standard')))->map(fn (string $field): TextInput => TextInput::make("limits.$field")
                    ->label(FilamentHelp::label(SecurityConfig::LIMIT_LABELS[$field][0] ?? str($field)->replace('_', ' ')->ucfirst()->toString(), SecurityConfig::LIMIT_LABELS[$field][1] ?? null))->numeric()
                    ->placeholder((string) (config('security.profiles.standard')[$field] ?? ''))
                    ->minValue($field === 'origin_retry_limit' ? 0 : 1)
                    ->maxValue(fn (Get $get): int => SecurityConfig::ceilingLimits((string) $get('profile'))[$field])
                    ->disabled(fn (Get $get): bool => $get('profile') !== SecurityConfig::MANUAL_PROFILE)->dehydrated()->required())->all(),
            ])->fillForm(fn (): array => $this->record->security_settings ?? SecurityConfig::defaults())
                ->action(function (array $data): void {
                    $settings = SecurityConfig::validateSettings($data);
                    DB::transaction(function () use ($settings): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $domain->update(['security_settings' => $settings, 'revision' => $domain->revision + 1]);
                        AuditLog::record(auth()->user(), 'security.settings_updated', $domain, ['profile' => $settings['profile'], 'revision' => $domain->revision], request()->ip());
                    });
                    $this->record->refresh();
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    Notification::make()->success()->title('Security profile saved')->body('The bounded policy is queued in the normal signed edge revision.')->send();
                }),
            Action::make('wafProfile')->label('Web application firewall (WAF)')->icon('phosphor-shield-warning')->extraAttributes(['data-testid' => 'waf-profile-action'])
                ->modalHeading('Choose web application firewall protection')
                ->modalDescription('WAF inspects web requests for common application attacks. It is separate from rate limits and emergency incident protection.')
                ->schema([
                    Select::make('profile')->label('Protection level')->options([
                        'off' => 'Off: no attack inspection',
                        'monitor' => 'Observe: report attacks but allow requests',
                        'balanced' => 'Recommended: block common attacks',
                        'strict' => 'High sensitivity: stronger blocking, more false positives',
                    ])->helperText('Start with Observe. After checking security events, choose Recommended. Use High sensitivity only after application testing.')->required(),
                    Section::make('Adjust managed rules')->collapsed()->compact()->schema([
                        Placeholder::make('ruleset')->label('Ruleset')->content(fn (): string => (string) config('security.waf.ruleset')),
                        Select::make('paranoia_level')->label(FilamentHelp::label('Paranoia level', 'Higher levels add stricter rules and more false positives. Leave it on the default of the protection level unless testing showed a need.'))
                            ->options([1 => '1 (fewest false positives)', 2 => '2', 3 => '3', 4 => '4 (strictest)'])->placeholder('Use the protection level default'),
                        TextInput::make('inbound_threshold')->label(FilamentHelp::label('Anomaly threshold', 'The score at which a request is blocked. Each matched rule adds 2 to 5 points. A higher number blocks fewer requests.'))
                            ->numeric()->minValue(1)->maxValue(100)->placeholder('5'),
                        CheckboxList::make('disabled_categories')->label(FilamentHelp::label('Turn off categories', 'Every rule in a checked category stops running for this domain. Use it when a whole kind of check does not fit your application.'))
                            ->options(ManagedWafTuning::categoryLabels())->columns(2),
                        Select::make('disabled_rules')->label(FilamentHelp::label('Turn off single rules', 'A disabled rule never runs for this domain. Up to '.ManagedWafTuning::MAXIMUM_RULES.' rules.'))
                            ->multiple()->searchable()->maxItems(ManagedWafTuning::MAXIMUM_RULES)->options(self::wafRuleOptions())->placeholder('Search by number or name, for example 942100'),
                        Select::make('log_only_rules')->label(FilamentHelp::label('Log only', 'A log-only rule still runs but never counts toward blocking. The security events show when one matched.'))
                            ->multiple()->searchable()->maxItems(ManagedWafTuning::MAXIMUM_RULES)->options(self::wafRuleOptions())->placeholder('Search by number or name, for example 941100'),
                    ]),
                ])->fillForm(fn (): array => ['profile' => $this->record->waf_profile, ...ManagedWafTuning::normalize($this->record->waf_tuning)])
                ->action(function (array $data): void {
                    $response = app(WafController::class)->update(request()->merge(['profile' => $data['profile'], 'tuning' => Arr::only($data, ['paranoia_level', 'inbound_threshold', 'disabled_categories', 'disabled_rules', 'log_only_rules'])]), $this->record);
                    if ($response->getStatusCode() === 409) {
                        Notification::make()->danger()->title('WAF protection is unavailable')->body('No enabled pool offers the managed WAF yet. Ask an administrator to enable a WAF-capable shared pool; nothing was changed.')->send();

                        return;
                    }
                    $this->record->refresh();
                    Notification::make()->success()->title('WAF protection change queued')->body(match ($data['profile']) {
                        'off' => 'Attack inspection will be turned off after the edge accepts this revision.',
                        'monitor' => 'Observe mode will report likely attacks without blocking requests.',
                        'balanced' => 'Recommended protection will block common web attacks.',
                        'strict' => 'High-sensitivity protection will block more aggressively; watch for false positives.',
                    })->send();
                }),
            Action::make('securityAlert')->label('Security alert')->icon('phosphor-bell-ringing')->extraAttributes(['data-testid' => 'security-alert-action'])
                ->modalHeading('Alert on mitigated requests')
                ->modalDescription('Send one notification when your rules and the managed firewall block this many requests in a period. It is sent at most once an hour.')
                ->extraModalFooterActions(fn (): array => $this->turnOffAction('security_alert', SecurityAlertController::class, 'Security alert removed', 'Remove alert'))
                ->schema([
                    TextInput::make('threshold')->label(FilamentHelp::label('Blocked requests', 'The number of blocked or challenged requests, counted over every location, that triggers the alert. Requests that a rule only logs do not count.'))
                        ->numeric()->required()->minValue(1)->maxValue(1000000)->placeholder('500'),
                    Select::make('period_minutes')->label(FilamentHelp::label('Within', 'The window the requests are counted in. A longer window needs a sustained attack to trigger.'))
                        ->options(SecurityAlertData::PERIODS)->default(5)->required()->placeholder('Choose a period'),
                ])->fillForm(fn (): array => $this->record->security_alert ?? ['period_minutes' => 5])
                ->action(function (array $data): void {
                    app(SecurityAlertController::class)->update(request()->merge($data), $this->record);
                    $this->record->refresh();
                    Notification::make()->success()->title('Security alert saved')->body('You will be notified when '.number_format((int) $data['threshold']).' requests are blocked within '.$data['period_minutes'].' minutes.')->send();
                }),
            Action::make('credentialCheck')->label('Credential check')->icon('phosphor-key')->extraAttributes(['data-testid' => 'credential-check-action'])
                ->modalHeading('Check logins against leaked passwords')
                ->modalDescription('Name the login paths and where the password is sent. Rules can then act on Leaked password. The password is checked on the cell and is never stored or logged.')
                ->extraModalFooterActions(fn (): array => $this->turnOffAction('credential_check', CredentialCheckController::class, 'Credential check turned off', 'Turn off'))
                ->schema([
                    Repeater::make('login_paths')->label(FilamentHelp::label('Login paths', 'Each path that receives a username and password, how the body is sent, and the names of its fields. Up to '.CredentialCheckData::MAXIMUM_LOGIN_PATHS.'.'))->minItems(1)->maxItems(CredentialCheckData::MAXIMUM_LOGIN_PATHS)->addActionLabel('Add login path')->table([
                        TableColumn::make('Path'), TableColumn::make('Format'), TableColumn::make('Password field'), TableColumn::make('Username field'),
                    ])->schema([
                        TextInput::make('path')->label('Path')->required()->maxLength(200)->placeholder('/api/login')->extraInputAttributes(['data-testid' => 'credential-path']),
                        Select::make('format')->label('Format')->options(['form' => 'Form fields', 'json' => 'JSON'])->default('json')->required()->extraInputAttributes(['aria-label' => 'Body format']),
                        TextInput::make('password_field')->label('Password field')->required()->maxLength(64)->placeholder('password'),
                        TextInput::make('username_field')->label('Username field')->maxLength(64)->placeholder('email'),
                    ])->default([['format' => 'json']]),
                ])->fillForm(fn (): array => $this->record->credential_check ?? ['login_paths' => [['path' => '', 'format' => 'json', 'password_field' => '', 'username_field' => '']]])
                ->action(function (array $data): void {
                    app(CredentialCheckController::class)->update(request()->merge($data), $this->record);
                    $this->record->refresh();
                    Notification::make()->success()->title('Credential check saved')->body('Add a rule on Leaked password to log, challenge, or block those logins.')->send();
                }),
            Action::make('sensitiveDataCheck')->label('Sensitive data check')->icon('phosphor-eye-slash')->extraAttributes(['data-testid' => 'sensitive-data-action'])
                ->modalHeading('Watch responses for sensitive data')
                ->modalDescription('The cell reads the first 16 KB of text responses and records a security event when it finds one of these. It only logs: the response is not changed and the value is never stored.')
                ->extraModalFooterActions(fn (): array => $this->turnOffAction('sensitive_data_check', SensitiveDataController::class, 'Sensitive data check turned off', 'Turn off'))
                ->schema([
                    CheckboxList::make('kinds')->label(FilamentHelp::label('Look for', 'Each kind is matched by a fixed pattern. Card numbers must pass the Luhn check and IBANs the country length and checksum, so ordinary numbers rarely match.'))
                        ->options(SensitiveDataCheckData::KINDS)->required()->minItems(1),
                ])->fillForm(fn (): array => $this->record->sensitive_data_check ?? ['kinds' => ['card', 'private_key']])
                ->action(function (array $data): void {
                    app(SensitiveDataController::class)->update(request()->merge($data), $this->record);
                    $this->record->refresh();
                    Notification::make()->success()->title('Sensitive data check saved')->body('Matches appear in Security events.')->send();
                }),
            Action::make('aiProtection')->label('AI protection')->icon('phosphor-chat-circle-text')->extraAttributes(['data-testid' => 'ai-protection-action'])
                ->modalHeading('Score prompts sent to your AI endpoints')
                ->modalDescription('Name the paths that take a prompt and the JSON field that holds it. Rules can then act on Prompt injection score and Personal data in prompt. The check is pattern based: it matches known phrases and does not understand a prompt.')
                ->extraModalFooterActions(fn (): array => $this->turnOffAction('ai_protection', AiProtectionController::class, 'AI protection turned off', 'Turn off'))
                ->schema([
                    TagsInput::make('endpoints')->label(FilamentHelp::label('Endpoint paths', 'Exact paths that receive a prompt by POST or PUT. Up to five.'))
                        ->required()->placeholder('/v1/chat')->nestedRecursiveRules(['string', 'max:200']),
                    TextInput::make('prompt_field')->label(FilamentHelp::label('Prompt field', 'The JSON field with the prompt. A field of messages is read as text, or as a list of messages with content. Use dots for a nested field, such as input.prompt.'))
                        ->required()->maxLength(200)->placeholder('messages'),
                ])->fillForm(fn (): array => $this->record->ai_protection ?? ['endpoints' => [], 'prompt_field' => ''])
                ->action(function (array $data): void {
                    app(AiProtectionController::class)->update(request()->merge($data), $this->record);
                    $this->record->refresh();
                    Notification::make()->success()->title('AI protection saved')->body('Add a rule on Prompt injection score or Personal data in prompt to log or block.')->send();
                }),
            Action::make('apiSchema')->label('API schema')->icon('phosphor-brackets-curly')->extraAttributes(['data-testid' => 'api-schema-action'])
                ->modalHeading('Check requests against an OpenAPI schema')
                ->modalDescription(fn (): string => 'Paste an OpenAPI 3 document as JSON. The control plane keeps only a table of paths, methods, and parameters, up to '.ApiSchemaData::MAXIMUM_OPERATIONS.' operations. The cell sets API schema violation on requests that do not fit it; add a rule on that field to log or block them.'
                    .(is_array($this->record->api_schema) ? ' Current schema: '.($this->record->api_schema['title'] ?: 'untitled').', '.count($this->record->api_schema['operations']).' operations under '.$this->record->api_schema['base_path'].'.' : ''))
                ->extraModalFooterActions(fn (): array => $this->turnOffAction('api_schema', ApiSchemaController::class, 'API schema removed', 'Remove schema'))
                ->schema([
                    Textarea::make('document')->label(FilamentHelp::label('OpenAPI document', 'An OpenAPI 3 document in JSON, up to 256 KB. YAML is not accepted. Only paths, methods, and path, query, and header parameters are used; request bodies are checked for size only.'))
                        ->required()->rows(10)->extraInputAttributes(['class' => 'font-mono'])->placeholder('{"openapi": "3.0.3", "info": {"title": "Orders", "version": "1"}, "paths": {"/orders": {"get": {}}}}'),
                    TextInput::make('base_path')->label(FilamentHelp::label('Base path', 'Only requests under this path are checked. Paths in the document are matched after it. Use / to check every request.'))
                        ->required()->maxLength(100)->default('/')->placeholder('/api/v1'),
                    TextInput::make('maximum_body_bytes')->label(FilamentHelp::label('JSON body limit (bytes)', 'A request with a JSON body larger than this is a violation. Up to 1,048,576.'))
                        ->required()->numeric()->default(ApiSchemaData::DEFAULT_BODY_BYTES)->minValue(1)->maxValue(ApiSchemaData::MAXIMUM_BODY_BYTES)->placeholder('65536'),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(ApiSchemaController::class)->update(request()->merge($data), $this->record);
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title('The schema was not saved')->body(collect($exception->errors())->flatten()->first())->persistent()->send();
                        $action->halt();
                    }
                    $this->record->refresh();
                    Notification::make()->success()->title('API schema saved')->body('Violations appear in Security events. Add a rule on API schema violation to block them.')->send();
                }),
            Action::make('clientSecurity')->label('Client-side security')->icon('phosphor-browsers')->extraAttributes(['data-testid' => 'client-security-action'])
                ->modalHeading('Watch the scripts your pages load')
                ->modalDescription('The cell adds a report-only Content-Security-Policy to your HTML pages. Browsers then report each script and connection to the cell, which keeps the page, the type, and the origin of what was loaded. Nothing is blocked and no page content is stored. Anyone can send a report, so treat the list as a hint, not proof.')
                ->extraModalFooterActions(fn (): array => $this->turnOffAction('client_security', ClientSecurityController::class, 'Client-side security turned off', 'Turn off'))
                ->schema([
                    TextInput::make('maximum_reports_per_minute')->label(FilamentHelp::label('Reports per minute', 'Most reports one cell accepts for this domain each minute. The rest are dropped. A busy site sends many; 120 is enough to see every script within minutes.'))
                        ->required()->numeric()->minValue(10)->maxValue(600)->placeholder('120'),
                    TextInput::make('baseline_minutes')->label(FilamentHelp::label('Baseline window (minutes)', 'Scripts first seen in this time after you turn it on are marked approved and send no notification. Later new ones send one.'))
                        ->required()->numeric()->minValue(0)->maxValue(1440)->placeholder('60'),
                ])->fillForm(fn (): array => $this->record->client_security ?? ['maximum_reports_per_minute' => 120, 'baseline_minutes' => 60])
                ->action(function (array $data): void {
                    app(ClientSecurityController::class)->update(request()->merge($data), $this->record);
                    $this->record->refresh();
                    Notification::make()->success()->title('Client-side security saved')->body('Scripts appear in the Scripts and connections tab once browsers report them.')->send();
                }),
            Action::make('startIncident')->icon('phosphor-shield-warning')->label('Under attack mode')->color('danger')->extraAttributes(['data-testid' => 'incident-start-action'])
                ->modalHeading('Turn on under attack mode?')
                ->modalDescription('For the time you choose, the domain gets tighter request limits, the web application firewall in blocking mode, restricted methods and body size, and a browser challenge. It protects your origin and the cells from request floods; it cannot absorb traffic that saturates a host or link.')
                ->modalSubmitActionLabel('Turn on')
                ->schema([Select::make('duration_minutes')->label(FilamentHelp::label('Duration', 'How long the protections stay on. They turn off by themselves at the end; you can end them sooner.'))
                    ->options([15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 180 => '3 hours', 720 => '12 hours', 1440 => '24 hours'])->default(60)->required()])
                ->visible(fn (): bool => ! IncidentProfile::active($this->record))
                ->action(function (array $data): void {
                    StartIncidentProfile::execute($this->record, auth()->user(), (int) $data['duration_minutes'], request()->ip());
                    $this->refreshDomain(includeTabs: true);
                    Notification::make()->warning()->title('Under attack mode is on')->body('The protections are on their way to the cells and end on their own.')->send();
                }),
            Action::make('endIncident')->icon('phosphor-shield-check')->label('End under attack mode')->color('success')->requiresConfirmation()->extraAttributes(['data-testid' => 'incident-end-action'])
                ->modalHeading('End under attack mode now?')
                ->visible(fn (): bool => IncidentProfile::active($this->record))
                ->action(function (): void {
                    EndIncidentProfile::execute($this->record, auth()->user(), 'manual', request()->ip());
                    $this->refreshDomain(includeTabs: true);
                    Notification::make()->success()->title('Under attack mode ended')->send();
                }),
            Action::make('startMaintenance')->icon('phosphor-wrench')->label('Start maintenance')->color('warning')->requiresConfirmation()->schema([
                TextInput::make('body')->label(FilamentHelp::label('503 response message', 'Every visitor gets this text with status 503 until maintenance ends. The origin is not contacted.'))->default('Service temporarily unavailable')->maxLength(4096)->required()->placeholder('Back at 14:00 UTC'),
            ])->visible(fn (): bool => ! is_array($this->record->proxy_settings['maintenance'] ?? null))
                ->action(fn (array $data) => $this->changeMaintenance((string) $data['body'])),
            Action::make('endMaintenance')->icon('phosphor-check-circle')->label('End maintenance')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => is_array($this->record->proxy_settings['maintenance'] ?? null))
                ->action(fn () => $this->changeMaintenance(null)),
            Action::make('quarantineSecurity')->icon('phosphor-prohibit')->label('Quarantine domain')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true && $this->record->security_state !== 'quarantined' && $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(fn () => $this->changeSecurityState('quarantined', EdgePool::query()->where('enabled', true)->where('withdrawn', false)->where('kind', 'quarantine')->orderBy('id')->firstOrFail())),
            Action::make('returnSecurityToNormal')->icon('phosphor-shield-check')->label('Return to normal')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true && in_array($this->record->security_state, ['restricted', 'quarantined'], true))
                ->action(fn () => $this->changeSecurityState('recovering', EdgePool::query()->where('enabled', true)->where('withdrawn', false)->where('kind', 'shared')->orderBy('id')->firstOrFail())),
            Action::make('rollbackProxy')->icon('phosphor-arrow-counter-clockwise')->label('Rollback proxy revision')->color('warning')->requiresConfirmation()->schema([
                Select::make('revision')->label(FilamentHelp::label('Revision', 'An earlier validated configuration. Rolling back saves it again as a new revision; nothing is lost.'))->placeholder('Choose a revision')->options(fn (): array => EdgeRevision::query()->where('domain_id', $this->record->id)->where('status', 'validated')->where('revision', '<', $this->record->revision)->latest('revision')->limit(50)->get()
                    ->mapWithKeys(fn (EdgeRevision $revision): array => [$revision->revision => "#{$revision->revision} · {$revision->created_at->copy()->setTimezone(app(PlatformSettings::class)->displayTimezone())->format('Y-m-d H:i:s')}"])->all())->required(),
            ])->visible(fn (): bool => EdgeRevision::query()->where('domain_id', $this->record->id)->where('status', 'validated')->where('revision', '<', $this->record->revision)->exists())
                ->action(function (array $data): void {
                    $prior = EdgeRevision::query()->where('domain_id', $this->record->id)->where('revision', $data['revision'])->where('status', 'validated')->firstOrFail();
                    ProxyRevisionRollback::apply($this->record, $prior, auth()->user(), request()->ip());
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                }),
            Action::make('moveEdgePool')->label('Move service pool')->icon('phosphor-arrows-left-right')->schema([
                Select::make('pool_id')->label(FilamentHelp::label('Target pool', 'The domain starts on the new pool first and leaves the old one only after the new one is ready.'))->placeholder('Choose a pool')->options(function (): array {
                    $placement = DomainEdgePlacement::query()->where('domain_id', $this->record->id)->first();

                    return EdgePool::query()->where('enabled', true)->where('withdrawn', false)
                        ->whereNotIn('id', array_values(array_filter([$placement?->target_pool_id, $placement?->active_pool_id])))
                        ->orderBy('name')->pluck('name', 'id')->all();
                })->required(),
            ])->visible(fn (): bool => auth()->user()?->isAdmin() === true && $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(function (array $data): void {
                    [$operation, $pool, $domain] = DB::transaction(function () use ($data): array {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $pool = EdgePool::query()->whereKey($data['pool_id'])->where('enabled', true)->where('withdrawn', false)->lockForUpdate()->firstOrFail();
                        $placement = DomainEdgePlacement::query()->where('domain_id', $domain->id)->lockForUpdate()->first();
                        abort_if(in_array($pool->id, [$placement?->active_pool_id, $placement?->target_pool_id], true), 422, 'Select a different service pool.');
                        if ($pool->kind === 'dedicated') {
                            abort_if(DomainEdgePlacement::query()->where('domain_id', '!=', $domain->id)
                                ->where(fn ($query) => $query->where('active_pool_id', $pool->id)->orWhere('target_pool_id', $pool->id))->exists(), 409, 'A dedicated pool can be assigned to only one domain.');
                        }
                        $domain->update(['revision' => $domain->revision + 1]);
                        DomainEdgePlacement::query()->updateOrCreate(['domain_id' => $domain->id], ['target_pool_id' => $pool->id, 'desired_revision' => $domain->revision, 'state' => 'deploying', 'drain_after' => null, 'last_error' => null]);
                        AuditLog::record(auth()->user(), 'edge.domain_move_requested', $domain, ['target_pool_id' => $pool->id, 'revision' => $domain->revision], request()->ip());
                        $operation = Operation::coalesceDomain('edge.domain_reconcile', $domain->id, auth()->id());

                        return [$operation, $pool, $domain];
                    });
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    $this->record->refresh();
                    Notification::make()->success()->title('Service pool move queued')
                        ->body("Operation {$operation->id} is moving {$domain->name} to {$pool->name} at revision {$domain->revision}. The source remains active until target readiness is acknowledged.")->send();
                }),
            Action::make('verifyNameservers')->label('Verify nameservers')->icon('phosphor-shield-check')
                ->visible(fn (): bool => $this->record->nameservers_verified_at === null && $this->record->lifecycle_state !== DomainLifecycleState::Deprovisioning)
                ->action(function (): void {
                    $operation = app(DomainNameserverVerification::class)->queue($this->record, auth()->user(), request()->ip());
                    Notification::make()->info()->title('Nameserver verification queued')
                        ->body("Operation {$operation->id} checks the public NS delegation. Refresh this page after the worker completes.")->send();
                }),
            Action::make('forceVerifyNameservers')->label('Force verify (local test)')->icon('phosphor-wrench')
                ->color('warning')->requiresConfirmation()
                ->modalHeading('Bypass public nameserver verification?')
                ->modalDescription('Use only for local browser qualification. This does not prove that public delegation is correct.')
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true
                    && $this->record->nameservers_verified_at === null
                    && $this->record->lifecycle_state !== DomainLifecycleState::Deprovisioning)
                ->action(function (): void {
                    $verification = app(DomainNameserverVerification::class);
                    $activated = $verification->complete($this->record, auth()->user(), ipAddress: request()->ip(), forced: true);
                    if ($activated) {
                        $verification->dispatchActivation($this->record->id, auth()->id());
                    }
                    $this->record->refresh();
                    Notification::make()->warning()->title('Nameservers force verified')
                        ->body('Local-test bypass recorded and the domain activated. Public delegation was not checked.')->send();
                }),
            Action::make('activate')->icon('phosphor-check-circle')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->nameservers_verified_at !== null && $this->record->lifecycle_state !== DomainLifecycleState::Active && $this->record->lifecycle_state !== DomainLifecycleState::Deprovisioning)
                ->action(function (): void {
                    abort_unless(
                        DnsCluster::query()->where('enabled', true)->where('last_health_status', 'healthy')->exists(),
                        409,
                        'Enable at least one healthy DNS cluster before activation.',
                    );
                    DB::transaction(function (): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        Gate::authorize('update', $domain);
                        abort_if($domain->lifecycle_state === DomainLifecycleState::Deprovisioning || $domain->nameservers_verified_at === null, 409, 'The domain is no longer eligible for activation.');
                        if ($domain->lifecycle_state === DomainLifecycleState::Active) {
                            return;
                        }
                        $domain->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'disabled_at' => null, 'revision' => $domain->revision + 1])->save();
                        AuditLog::record(auth()->user(), 'domain.activated', $domain, ['revision' => $domain->revision], request()->ip());
                    });
                    ReconcileDnsZone::dispatch($this->record->id)->afterCommit();
                    if ($this->record->dnsRecords()->where('mode', 'proxied')->exists() || EdgeArtifact::query()->where('domain_id', $this->record->id)->exists()) {
                        Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                        ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                        EnsureManagedCertificates::dispatch($this->record->id)->afterCommit();
                    }
                }),
            Action::make('deleteDomain')->label('Delete domain')->icon('phosphor-trash')->color('danger')->requiresConfirmation()
                ->extraAttributes(['data-testid' => 'domain-delete-action'])
                ->modalHeading(fn (): string => "Delete {$this->domain()->name}?")
                ->modalDescription(function (): string {
                    $days = app(PlatformSettings::class)->integer('dns_lifecycle', 'deprovision_delay_days');

                    return "Traffic and DNS for this domain stop being served, and its zone, records, and certificates are removed after {$days} days.";
                })
                ->modalSubmitActionLabel('Delete domain')
                ->visible(fn (): bool => $this->domain()->lifecycle_state !== DomainLifecycleState::Deprovisioning && Gate::allows('delete', $this->domain()))
                ->action(function (): void {
                    $response = app(DomainController::class)->destroy(request(), $this->domain());
                    $operation = $response->getData(true)['data'];
                    $this->record->refresh();
                    Notification::make()->info()->title('Domain deletion queued')
                        ->body("Operation {$operation['operation_id']} is removing the zone. The domain shows as Deprovisioning until it finishes.")->send();
                }),
            Action::make('disable')->icon('phosphor-prohibit')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->record->lifecycle_state, [DomainLifecycleState::PendingVerification, DomainLifecycleState::Active], true))
                ->action(function (): void {
                    DB::transaction(function (): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        Gate::authorize('update', $domain);
                        if (! in_array($domain->lifecycle_state, [DomainLifecycleState::PendingVerification, DomainLifecycleState::Active], true)) {
                            return;
                        }
                        $domain->forceFill(['lifecycle_state' => DomainLifecycleState::Disabled, 'disabled_at' => now(), 'revision' => $domain->revision + 1])->save();
                        AuditLog::record(auth()->user(), 'domain.disabled', $domain, ['revision' => $domain->revision], request()->ip());
                    });
                    if ($this->record->dnsRecords()->where('mode', 'proxied')->exists() || EdgeArtifact::query()->where('domain_id', $this->record->id)->exists()) {
                        Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                        ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    }
                }),
            Action::make('importZone')->icon('phosphor-upload-simple')->label('Import zone')->schema([
                Textarea::make('zone')->label(FilamentHelp::label('Zone file', 'A BIND zone file. SOA and NS records are managed by the platform and skipped.'))->required()->maxLength(BindZone::MAX_BYTES)->rows(12)->placeholder('www 300 IN A 192.0.2.10'),
                Toggle::make('replace_existing')->label(FilamentHelp::label('Replace existing records', 'Off adds the imported records to the current ones. On removes every current record first.'))->default(false),
            ])->action(function (array $data): void {
                if (strlen($data['zone']) > 65536 || substr_count($data['zone'], "\n") + 1 > 100) {
                    $operation = Operation::query()->create(['actor_id' => auth()->id(), 'type' => 'dns.zone_import', 'status' => 'pending', 'input' => ['domain_id' => $this->record->id, 'zone' => $data['zone'], 'replace_existing' => (bool) $data['replace_existing'], 'ip_address' => request()->ip()]]);
                    ImportDnsZone::dispatch($operation->id)->afterCommit();

                    return;
                }
                $records = BindZone::parse($data['zone'], $this->record->name);
                DnsZoneImporter::apply($this->record->id, $records, (bool) $data['replace_existing'], auth()->user(), request()->ip());
                if ($this->record->refresh()->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
                    ReconcileDnsZone::dispatch($this->record->id)->afterCommit();
                }
            }),
            Action::make('exportZone')->icon('phosphor-download-simple')->label('Export zone')->action(function (): StreamedResponse {
                $zone = BindZone::export($this->record);

                return response()->streamDownload(
                    static function () use ($zone): void {
                        echo $zone;
                    },
                    $this->record->name.'.zone',
                    ['Content-Type' => 'text/dns; charset=utf-8'],
                );
            }),
        ];
        // Long forms open as a right-side sheet; a form of one or two fields opens as a small centered dialog.
        $sheets = ['importZone', 'proxyDefaults', 'cacheSettings', 'uploadCertificate', 'securitySettings', 'wafProfile', 'credentialCheck', 'sensitiveDataCheck', 'aiProtection', 'apiSchema', 'clientSecurity'];
        $dialogs = ['moveEdgePool', 'developmentMode', 'purgeCache', 'tlsMode', 'securityAlert', 'startIncident', 'startMaintenance', 'rollbackProxy'];

        // Every form names what its button does; a generic "Submit" never shows.
        $submit = ['importZone' => 'Import', 'uploadCertificate' => 'Upload', 'tlsMode' => 'Save', 'developmentMode' => 'Turn on', 'startMaintenance' => 'Start maintenance',
            'moveEdgePool' => 'Move', 'rollbackProxy' => 'Roll back', 'proxyDefaults' => 'Save', 'cacheSettings' => 'Save', 'securitySettings' => 'Save', 'wafProfile' => 'Save',
            'securityAlert' => 'Save', 'credentialCheck' => 'Save', 'sensitiveDataCheck' => 'Save', 'aiProtection' => 'Save', 'apiSchema' => 'Save', 'clientSecurity' => 'Save'];

        return collect($actions)
            ->keyBy(fn (Action $action): string => $action->getName())
            ->each(fn (Action $action, string $name) => isset($submit[$name]) ? $action->modalSubmitActionLabel($submit[$name]) : null)
            ->map(fn (Action $action): Action => match (true) {
                in_array($action->getName(), $sheets, true) => $action->slideOver()->modalWidth(Width::TwoExtraLarge),
                in_array($action->getName(), $dialogs, true) => $action->modalWidth(Width::Medium),
                default => $action,
            })
            ->all();
    }

    /** The header keeps what concerns the whole domain; settings sit beside their section on the Overview tab. */
    protected function getHeaderActions(): array
    {
        $actions = $this->domainActions();

        return [
            $actions['verifyNameservers'],
            $actions['startIncident'],
            ActionGroup::make(array_values(Arr::only($actions, ['reconcileDns', 'importZone', 'exportZone', 'activate', 'forceVerifyNameservers', 'moveEdgePool', 'quarantineSecurity', 'returnSecurityToNormal', 'disable', 'deleteDomain'])))
                ->label('More actions')->icon('phosphor-dots-three')->color('gray')->button()->extraAttributes(['data-testid' => 'domain-more-actions']),
        ];
    }

    /** Actions shown beside the Overview sections are not in the header, so they are cached here to stay mountable. */
    public function cacheInteractsWithHeaderActions(): void
    {
        parent::cacheInteractsWithHeaderActions();
        foreach ($this->domainActions() as $name => $action) {
            if (! array_key_exists($name, $this->cachedActions)) {
                $this->cacheAction($action);
            }
        }
    }

    /** Whether a named action exists and is visible right now, for the buttons the Overview tab draws itself. */
    public function canShowDomainAction(string $name): bool
    {
        return $this->getAction($name, isMounting: false)?->isVisible() === true;
    }

    /**
     * The "Turn off" button in a detection's settings sheet, present only while the detection is on. A detection whose fields
     * custom rules still use is refused with the reason, and the sheet stays open.
     *
     * @param  class-string<DetectionSettingsController|SecurityAlertController>  $controller
     * @return array<int, Action>
     */
    private function turnOffAction(string $column, string $controller, string $title, string $label): array
    {
        if ($this->domain()->{$column} === null) {
            return [];
        }

        return [Action::make('turnOff')->label($label)->color('danger')->requiresConfirmation()->extraAttributes(['data-testid' => 'settings-turn-off'])
            ->modalHeading($label.'?')->modalSubmitActionLabel($label)->cancelParentActions()
            ->action(function (Action $action) use ($controller, $title): void {
                try {
                    app($controller)->destroy(request(), $this->domain());
                } catch (HttpException $exception) {
                    Notification::make()->danger()->title('Nothing was changed')->body($exception->getMessage())->persistent()->send();
                    $action->halt();
                }
                $this->record->refresh();
                Notification::make()->success()->title($title)->send();
            })];
    }

    private function changeSecurityState(string $state, ?EdgePool $pool): void
    {
        DB::transaction(function () use ($pool, $state): void {
            $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
            $domain->update(['security_state' => $state, 'security_state_changed_at' => now(), 'revision' => $domain->revision + 1]);
            if ($pool !== null) {
                DomainEdgePlacement::query()->updateOrCreate(['domain_id' => $domain->id], [
                    'target_pool_id' => $pool->id, 'desired_revision' => $domain->revision,
                    'state' => 'deploying', 'drain_after' => null, 'last_error' => null,
                ]);
            }
            if ($state === 'quarantined') {
                $domain->securityEvents()->create([
                    'state' => $state, 'reason_code' => 'domain_quarantined',
                    'details' => ['actor_id' => auth()->id(), 'target_pool_id' => $pool?->id], 'occurred_at' => now(),
                ]);
            }
            AuditLog::record(auth()->user(), "security.domain_$state", $domain, ['revision' => $domain->revision, 'target_pool_id' => $pool?->id], request()->ip());
            if (in_array($state, ['recovering', 'normal'], true)) {
                ChangeAutomaticSecurityState::execute($domain, 'normal', 'operator_return_to_normal', ['actor_id' => auth()->id()]);
            }
        });
        $operation = Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
        ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
        Notification::make()->warning()->title('Security state queued')->body("{$state} is deploying as operation {$operation->id}; placement changes activate the target before draining the source.")->send();
    }

    private function changeMaintenance(?string $body): void
    {
        DB::transaction(function () use ($body): void {
            $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
            $settings = is_array($domain->proxy_settings) ? $domain->proxy_settings : ReconcileEdgeDomain::defaults();
            $settings['maintenance'] = $body === null ? null : ['status' => 503, 'body' => $body];
            $domain->update(['proxy_settings' => $settings, 'revision' => $domain->revision + 1]);
            AuditLog::record(
                auth()->user(),
                $body === null ? 'proxy.maintenance_ended' : 'proxy.maintenance_started',
                $domain,
                ['revision' => $domain->revision],
                request()->ip(),
            );
        });
        $operation = Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
        ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
        Notification::make()->title($body === null ? 'Maintenance ending' : 'Maintenance starting')
            ->body("Operation {$operation->id} is deploying the change without reloading the edge.")
            ->color($body === null ? 'success' : 'warning')->send();
    }
}
