<?php

namespace App\Filament\Admin\Resources\DnsClusters\Pages;

use App\Enums\DomainLifecycleState;
use App\Filament\Admin\Resources\DnsClusters\DnsClusterResource;
use App\Jobs\ReconcileDnsZone;
use App\Models\AuditLog;
use App\Models\Domain;
use Filament\Resources\Pages\CreateRecord;

class CreateDnsCluster extends CreateRecord
{
    protected static string $resource = DnsClusterResource::class;

    protected function afterCreate(): void
    {
        AuditLog::record(auth()->user(), 'dns.cluster_created', $this->record, ['name' => $this->record->name], request()->ip());
        if ($this->record->enabled) {
            Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)->orderBy('id')
                ->chunkById(100, fn ($domains) => $domains->each(fn ($domain) => ReconcileDnsZone::dispatch($domain->id)));
        }
    }
}
