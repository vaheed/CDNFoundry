<?php

namespace App\Filament\Domain\Resources\Domains\Pages;

use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Jobs\ImportDnsZone;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\VerifyDomainNameservers;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Operation;
use App\Support\BindZone;
use App\Support\DnsZoneImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verifyNameservers')->label('Verify nameservers')->icon('heroicon-o-shield-check')
                ->visible(fn (): bool => $this->record->nameservers_verified_at === null && $this->record->lifecycle_state !== DomainLifecycleState::Deprovisioning)
                ->action(function (): void {
                    $operation = Operation::query()->where('type', 'domain.nameservers_verify')->whereIn('status', ['pending', 'running'])->where('input->domain_id', $this->record->id)->first();
                    if ($operation === null) {
                        Operation::query()->create(['actor_id' => auth()->id(), 'type' => 'domain.nameservers_verify', 'status' => 'pending', 'input' => ['domain_id' => $this->record->id]]);
                        AuditLog::record(auth()->user(), 'domain.nameserver_verification_requested', $this->record, [], request()->ip());
                        VerifyDomainNameservers::dispatch($this->record->id)->afterCommit();
                    }
                }),
            Action::make('activate')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->nameservers_verified_at !== null && $this->record->lifecycle_state !== DomainLifecycleState::Active && $this->record->lifecycle_state !== DomainLifecycleState::Deprovisioning)
                ->action(function (): void {
                    DB::transaction(function (): void {
                        $domain = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $domain->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'disabled_at' => null, 'revision' => $domain->revision + 1])->save();
                        AuditLog::record(auth()->user(), 'domain.activated', $domain, ['revision' => $domain->revision], request()->ip());
                    });
                    if (DnsCluster::query()->where('enabled', true)->exists()) {
                        ReconcileDnsZone::dispatch($this->record->id)->afterCommit();
                    }
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
            Action::make('exportZone')->label('Export zone')->url(fn (): string => url("/api/domains/{$this->record->id}/dns/export"))->openUrlInNewTab(),
        ];
    }
}
