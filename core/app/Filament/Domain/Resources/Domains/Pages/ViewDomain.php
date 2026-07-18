<?php

namespace App\Filament\Domain\Resources\Domains\Pages;

use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Jobs\ImportDnsZone;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\VerifyDomainNameservers;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Operation;
use App\Support\BindZone;
use App\Support\DnsZoneImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deployProxy')->label('Deploy proxy configuration')->icon('heroicon-o-cloud-arrow-up')
                ->visible(fn (): bool => $this->record->dnsRecords()->where('mode', 'proxied')->exists())
                ->action(function (): void {
                    $operation = Operation::coalesceDomain('edge.domain_reconcile', $this->record->id, auth()->id());
                    ReconcileEdgeDomain::dispatch($this->record->id)->afterCommit();
                    Notification::make()->info()->title('Edge deployment queued')->body("Operation {$operation->id} will deploy the latest desired revision.")->send();
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
    }
}
