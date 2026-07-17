<?php

namespace App\Filament\Admin\Resources\DnsClusters\Pages;

use App\Enums\DomainLifecycleState;
use App\Filament\Admin\Resources\DnsClusters\DnsClusterResource;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\TestDnsCluster;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditDnsCluster extends EditRecord
{
    protected static string $resource = DnsClusterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')->label('Test connection')->action(function (): void {
                $operation = Operation::query()->where('type', 'dns.cluster_test')->whereIn('status', ['pending', 'running'])->where('input->cluster_id', $this->record->id)->first();
                if ($operation === null) {
                    $operation = Operation::query()->create(['actor_id' => auth()->id(), 'type' => 'dns.cluster_test', 'status' => 'pending', 'input' => ['cluster_id' => $this->record->id]]);
                    AuditLog::record(auth()->user(), 'dns.cluster_test_requested', $this->record, [], request()->ip());
                    TestDnsCluster::dispatch($operation->id)->afterCommit();
                }
            }),
        ];
    }

    protected function afterSave(): void
    {
        AuditLog::record(auth()->user(), 'dns.cluster_updated', $this->record, [], request()->ip());
        if ($this->record->wasChanged('enabled') && $this->record->enabled) {
            Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)->orderBy('id')
                ->chunkById(100, fn ($domains) => $domains->each(fn ($domain) => ReconcileDnsZone::dispatch($domain->id)));
        }
    }
}
