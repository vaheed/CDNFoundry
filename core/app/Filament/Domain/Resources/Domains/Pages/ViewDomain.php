<?php

namespace App\Filament\Domain\Resources\Domains\Pages;

use App\Actions\DispatchCachePurge;
use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Http\Controllers\CacheController;
use App\Jobs\EnsureManagedCertificates;
use App\Jobs\ImportDnsZone;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\VerifyDomainNameservers;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Support\BindZone;
use App\Support\DnsZoneImporter;
use App\Support\ProxyRevisionRollback;
use App\Support\UploadedCertificate;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    public function getTitle(): string
    {
        return $this->record->display_name ?: $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return $this->record->display_name && $this->record->display_name !== $this->record->name
            ? $this->record->name
            : 'Domain configuration and delivery status';
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('tlsMode')->label('TLS mode')->icon('heroicon-o-lock-closed')->schema([
                Select::make('mode')->options(['managed' => 'Managed', 'custom' => 'Custom', 'disabled' => 'Disabled'])->required(),
            ])->fillForm(fn (): array => ['mode' => $this->record->tls_mode])
                ->action(function (array $data): void {
                    if ($data['mode'] === 'custom') {
                        abort_unless($this->record->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->where('expires_at', '>', now())->exists(), 409, 'Upload a valid custom certificate before selecting custom mode.');
                    }
                    DB::transaction(function () use ($data): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $certificateId = $data['mode'] === 'custom'
                            ? $domain->tlsCertificates()->where('kind', 'custom')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->value('id')
                            : ($data['mode'] === 'managed' ? $domain->tlsCertificates()->where('kind', 'managed')->where('status', 'active')->where('expires_at', '>', now())->latest('activated_at')->value('id') : $domain->active_tls_certificate_id);
                        $domain->update(['tls_mode' => $data['mode'], 'active_tls_certificate_id' => $certificateId, 'revision' => $domain->revision + 1]);
                        AuditLog::record(auth()->user(), 'tls.mode_updated', $domain, ['mode' => $data['mode'], 'revision' => $domain->revision], request()->ip());
                    });
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    if ($data['mode'] === 'managed') {
                        EnsureManagedCertificates::dispatch($this->record->id)->afterCommit();
                    }
                }),
            Action::make('renewManagedCertificate')->label('Renew managed certificate')->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->record->tls_mode === 'managed' && $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(function (): void {
                    $operation = Operation::query()->create([
                        'actor_id' => auth()->id(), 'type' => 'tls.managed_renew', 'status' => 'pending',
                        'input' => ['domain_id' => $this->record->id],
                    ]);
                    EnsureManagedCertificates::dispatch($this->record->id)->afterCommit();
                    Notification::make()->info()->title('Managed renewal queued')->body("Operation {$operation->id} will reuse valid coverage or queue a bounded ACME order.")->send();
                }),
            Action::make('reissueManagedCertificate')->label('Reissue managed certificate')->color('warning')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->tls_mode === 'managed' && $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(function (): void {
                    $operation = Operation::query()->create([
                        'actor_id' => auth()->id(), 'type' => 'tls.managed_reissue', 'status' => 'pending',
                        'input' => ['domain_id' => $this->record->id],
                    ]);
                    EnsureManagedCertificates::dispatch($this->record->id, true)->afterCommit();
                    Notification::make()->warning()->title('Managed reissue queued')->body("Operation {$operation->id} will create a new order within the global CA budget.")->send();
                }),
            Action::make('uploadCertificate')->label('Upload custom certificate')->icon('heroicon-o-document-arrow-up')->schema([
                Textarea::make('certificate')->label('Leaf certificate PEM')->rows(8)->maxLength(16384)->required(),
                Textarea::make('chain')->label('Issuing chain PEM')->rows(8)->maxLength(65536)->required(),
                Textarea::make('private_key')->label('Private key PEM')->rows(8)->maxLength(16384)->required(),
            ])->action(function (array $data): void {
                $validated = UploadedCertificate::validate($this->record, $data['certificate'], $data['chain'], $data['private_key']);
                DB::transaction(function () use ($validated): void {
                    $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                    $certificate = $domain->tlsCertificates()->where('kind', 'custom')->where('fingerprint_sha256', $validated['fingerprint_sha256'])->first();
                    if ($certificate === null) {
                        $certificate = $domain->tlsCertificates()->create([
                            'kind' => 'custom', 'status' => 'active', 'certificate_pem' => $validated['certificate_pem'],
                            'chain_pem' => $validated['chain_pem'], 'private_key_ciphertext' => $validated['private_key'],
                            'names' => $validated['names'], 'fingerprint_sha256' => $validated['fingerprint_sha256'],
                            'not_before' => $validated['not_before'], 'expires_at' => $validated['expires_at'], 'activated_at' => now(),
                        ]);
                    } else {
                        $certificate->update(['status' => 'active', 'activated_at' => $certificate->activated_at ?? now(), 'last_error' => null]);
                    }
                    $domain->tlsCertificates()->where('kind', 'custom')->where('id', '!=', $certificate->id)->where('status', 'active')->update(['status' => 'superseded']);
                    $domain->update(['tls_mode' => 'custom', 'active_tls_certificate_id' => $certificate->id, 'revision' => $domain->revision + 1]);
                    AuditLog::record(auth()->user(), 'tls.custom_uploaded', $certificate, ['domain_id' => $domain->id, 'revision' => $domain->revision, 'fingerprint' => $certificate->fingerprint_sha256], request()->ip());
                });
                Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                Notification::make()->success()->title('Custom certificate accepted')->body('The encrypted key and validated chain are queued for edge delivery.')->send();
            }),
            Action::make('deleteCustomCertificate')->label('Remove custom certificate')->color('danger')->requiresConfirmation()
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
            Action::make('cacheSettings')->label('Cache settings')->icon('heroicon-o-circle-stack')->schema([
                Toggle::make('enabled')->label('Cache enabled')->required(),
                TextInput::make('edge_ttl_seconds')->label('Edge TTL (seconds)')->numeric()->minValue(0)->maxValue(31536000)->required(),
                TextInput::make('browser_ttl_seconds')->label('Browser TTL (seconds)')->numeric()->minValue(0)->maxValue(31536000)->required(),
                Select::make('maximum_object_bytes')->label('Maximum object size')->options([
                    1048576 => '1 MiB', 10485760 => '10 MiB', 104857600 => '100 MiB',
                ])->required(),
                Toggle::make('respect_origin_headers')->label('Respect origin cache headers')->required(),
                Toggle::make('include_query_string')->label('Include query string in cache key')->required(),
                TagsInput::make('bypass_cookie_names')->label('Bypass cookie names')->rules(['array', 'max:32']),
                TextInput::make('stale_if_error_seconds')->label('Stale-if-error (seconds)')->numeric()->minValue(0)->maxValue(86400)->required(),
            ])->fillForm(fn (): array => $this->record->cache_settings ?? CacheController::defaults())
                ->action(function (array $data): void {
                    $settings = [
                        'enabled' => (bool) $data['enabled'], 'edge_ttl_seconds' => (int) $data['edge_ttl_seconds'],
                        'browser_ttl_seconds' => (int) $data['browser_ttl_seconds'], 'maximum_object_bytes' => (int) $data['maximum_object_bytes'],
                        'respect_origin_headers' => (bool) $data['respect_origin_headers'], 'include_query_string' => (bool) $data['include_query_string'],
                        'bypass_cookie_names' => array_values($data['bypass_cookie_names'] ?? []), 'stale_if_error_seconds' => (int) $data['stale_if_error_seconds'],
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
            Action::make('developmentMode')->label('Enable development mode')->icon('heroicon-o-code-bracket')->schema([
                TextInput::make('duration_minutes')->label('Duration (minutes)')->numeric()->default(30)->minValue(1)->maxValue(1440)->required(),
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
            Action::make('disableDevelopmentMode')->label('Disable development mode')->color('warning')->requiresConfirmation()
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
            Action::make('purgeCache')->label('Purge cache')->icon('heroicon-o-trash')->color('warning')->schema([
                Select::make('type')->options(['all' => 'Everything', 'urls' => 'Exact URLs'])->default('all')->required()->live(),
                Textarea::make('urls')->label('URLs (one per line)')->rows(8)->maxLength(131072)->visible(fn ($get): bool => $get('type') === 'urls')->required(fn ($get): bool => $get('type') === 'urls'),
            ])->action(function (array $data): void {
                $urls = $data['type'] === 'urls' ? preg_split('/\R/', trim((string) $data['urls']), flags: PREG_SPLIT_NO_EMPTY) : [];
                abort_if(count($urls) > 100, 422, 'At most 100 URLs may be purged at once.');
                $purge = DispatchCachePurge::execute($this->record, $data['type'], $urls, auth()->user(), request()->ip());
                Notification::make()->info()->title('Cache purge queued')->body("Purge {$purge->id} is visible in per-edge delivery state.")->send();
            }),
            Action::make('proxyDefaults')->label('Proxy defaults')->icon('heroicon-o-adjustments-horizontal')->schema([
                Toggle::make('enabled')->default(true)->required(),
                Toggle::make('redirect_https')->label('Redirect HTTP to HTTPS')->default(false)->required(),
                Select::make('http_versions')->label('Allowed HTTP versions')->multiple()->options(['1.1' => 'HTTP/1.1', '2' => 'HTTP/2'])->required()->maxItems(2),
                TextInput::make('retry_count')->label('Origin retry count')->numeric()->default(0)->minValue(0)->maxValue(2)->required(),
                Toggle::make('maintenance_enabled')->label('Maintenance mode')->live(),
                TextInput::make('maintenance_body')->label('Maintenance response')->maxLength(4096)->visible(fn ($get): bool => (bool) $get('maintenance_enabled')),
            ])->fillForm(function (): array {
                $settings = is_array($this->record->proxy_settings) ? $this->record->proxy_settings : ReconcileEdgeDomain::defaults();

                return [...$settings, 'maintenance_enabled' => $settings['maintenance'] !== null, 'maintenance_body' => $settings['maintenance']['body'] ?? 'Service temporarily unavailable'];
            })->action(function (array $data): void {
                $settings = [
                    'enabled' => (bool) $data['enabled'], 'redirect_https' => (bool) $data['redirect_https'],
                    'http_versions' => array_values($data['http_versions']), 'retry_count' => (int) $data['retry_count'],
                    'maintenance' => $data['maintenance_enabled'] ? ['status' => 503, 'body' => $data['maintenance_body']] : null,
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
            Action::make('deployProxy')->label('Deploy proxy configuration')->icon('heroicon-o-cloud-arrow-up')
                ->visible(fn (): bool => $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(function (): void {
                    $operation = Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    Notification::make()->info()->title('Edge deployment queued')->body("Operation {$operation->id} will deploy the latest desired revision.")->send();
                }),
            Action::make('rollbackProxy')->label('Rollback proxy revision')->color('warning')->requiresConfirmation()->schema([
                Select::make('revision')->options(fn (): array => EdgeRevision::query()->where('domain_id', $this->record->id)->where('status', 'validated')->where('revision', '<', $this->record->revision)->latest('revision')->limit(50)->pluck('revision', 'revision')->all())->required(),
            ])->visible(fn (): bool => EdgeRevision::query()->where('domain_id', $this->record->id)->where('status', 'validated')->where('revision', '<', $this->record->revision)->exists())
                ->action(function (array $data): void {
                    $prior = EdgeRevision::query()->where('domain_id', $this->record->id)->where('revision', $data['revision'])->where('status', 'validated')->firstOrFail();
                    ProxyRevisionRollback::apply($this->record, $prior, auth()->user(), request()->ip());
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                }),
            Action::make('moveEdgePool')->label('Move service pool')->icon('heroicon-o-arrows-right-left')->schema([
                Select::make('pool_id')->label('Target pool')->options(fn (): array => EdgePool::query()->where('enabled', true)->orderBy('name')->pluck('name', 'id')->all())->required(),
            ])->visible(fn (): bool => auth()->user()?->isAdmin() === true && $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(function (array $data): void {
                    DB::transaction(function () use ($data): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $domain->update(['revision' => $domain->revision + 1]);
                        DomainEdgePlacement::query()->updateOrCreate(['domain_id' => $domain->id], ['target_pool_id' => $data['pool_id'], 'desired_revision' => $domain->revision, 'state' => 'deploying', 'last_error' => null]);
                        AuditLog::record(auth()->user(), 'edge.domain_move_requested', $domain, ['target_pool_id' => $data['pool_id'], 'revision' => $domain->revision], request()->ip());
                    });
                    Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                }),
            Action::make('verifyNameservers')->label('Verify nameservers')->icon('heroicon-o-shield-check')
                ->visible(fn (): bool => $this->record->nameservers_verified_at === null && $this->record->lifecycle_state !== DomainLifecycleState::Deprovisioning)
                ->action(function (): void {
                    $operation = Operation::query()->where('type', 'domain.nameservers_verify')->whereIn('status', ['pending', 'running'])->where('input->domain_id', $this->record->id)->first();
                    if ($operation === null) {
                        $operation = Operation::query()->create(['actor_id' => auth()->id(), 'type' => 'domain.nameservers_verify', 'status' => 'pending', 'input' => ['domain_id' => $this->record->id]]);
                        AuditLog::record(auth()->user(), 'domain.nameserver_verification_requested', $this->record, [], request()->ip());
                        VerifyDomainNameservers::dispatch($this->record->id)->afterCommit();
                    }
                    Notification::make()->info()->title('Nameserver verification queued')
                        ->body("Operation {$operation->id} checks the public NS delegation. Refresh this page after the worker completes.")->send();
                }),
            Action::make('forceVerifyNameservers')->label('Force verify (local test)')->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')->requiresConfirmation()
                ->modalHeading('Bypass public nameserver verification?')
                ->modalDescription('Use only for local browser qualification. This does not prove that public delegation is correct.')
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true
                    && $this->record->nameservers_verified_at === null
                    && $this->record->lifecycle_state !== DomainLifecycleState::Deprovisioning)
                ->action(function (): void {
                    $this->record->forceFill([
                        'nameservers_verified_at' => now(),
                        'nameservers_verified_by' => auth()->id(),
                    ])->save();
                    AuditLog::record(auth()->user(), 'domain.nameservers_force_verified', $this->record, ['name' => $this->record->name], request()->ip());
                    Notification::make()->warning()->title('Nameservers force verified')
                        ->body('Local-test bypass recorded. Public delegation was not checked.')->send();
                }),
            Action::make('activate')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->nameservers_verified_at !== null && $this->record->lifecycle_state !== DomainLifecycleState::Active && $this->record->lifecycle_state !== DomainLifecycleState::Deprovisioning)
                ->action(function (): void {
                    abort_unless(
                        DnsCluster::query()->where('enabled', true)->where('last_health_status', 'healthy')->exists(),
                        409,
                        'Enable at least one healthy DNS cluster before activation.',
                    );
                    DB::transaction(function (): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $domain->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'disabled_at' => null, 'revision' => $domain->revision + 1])->save();
                        AuditLog::record(auth()->user(), 'domain.activated', $domain, ['revision' => $domain->revision], request()->ip());
                    });
                    ReconcileDnsZone::dispatch($this->record->id)->afterCommit();
                }),
            Action::make('disable')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->lifecycle_state === DomainLifecycleState::Active)
                ->action(function (): void {
                    $this->record->forceFill(['lifecycle_state' => DomainLifecycleState::Disabled, 'disabled_at' => now(), 'revision' => $this->record->revision + 1])->save();
                    AuditLog::record(auth()->user(), 'domain.disabled', $this->record, ['revision' => $this->record->revision], request()->ip());
                }),
            Action::make('importZone')->label('Import zone')->schema([
                Textarea::make('zone')->required()->maxLength(BindZone::MAX_BYTES)->rows(12),
                Toggle::make('replace_existing')->label('Replace existing records')->default(false),
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
            Action::make('exportZone')->label('Export zone')->action(function (): StreamedResponse {
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
        $group = fn (array $names): array => collect($actions)
            ->keyBy(fn (Action $action): string => $action->getName())
            ->only($names)
            ->values()
            ->all();

        return [
            ActionGroup::make($group(['verifyNameservers', 'forceVerifyNameservers', 'activate', 'disable', 'importZone', 'exportZone']))
                ->label('Domain actions')
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->button(),
            ActionGroup::make($group(['proxyDefaults', 'deployProxy', 'rollbackProxy', 'moveEdgePool']))
                ->label('Delivery')
                ->icon('heroicon-o-cloud')
                ->button(),
            ActionGroup::make($group(['cacheSettings', 'developmentMode', 'disableDevelopmentMode', 'purgeCache']))
                ->label('Cache')
                ->icon('heroicon-o-circle-stack')
                ->color('gray')
                ->button(),
            ActionGroup::make($group(['tlsMode', 'renewManagedCertificate', 'reissueManagedCertificate', 'uploadCertificate', 'deleteCustomCertificate']))
                ->label('TLS')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->button(),
        ];
    }
}
