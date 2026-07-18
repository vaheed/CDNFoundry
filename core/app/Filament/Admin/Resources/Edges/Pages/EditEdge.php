<?php

namespace App\Filament\Admin\Resources\Edges\Pages;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\EdgePool;
use Filament\Resources\Pages\EditRecord;

class EditEdge extends EditRecord
{
    protected static string $resource = EdgeResource::class;

    private ?string $oldIpv4 = null;

    private ?string $oldIpv6 = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->oldIpv4 = $this->record->ipv4;
        $this->oldIpv6 = $this->record->ipv6;
        $data['country_code'] = strtoupper($data['country_code']);
        $data['continent_code'] = strtoupper($data['continent_code']);

        return $data;
    }

    protected function afterSave(): void
    {
        $defaultSharedId = EdgePool::query()->where('enabled', true)->where('kind', 'shared')->orderBy('id')->value('id');
        if ($defaultSharedId !== null) {
            $this->record->cells()->where('edge_pool_id', $defaultSharedId)->where('service_ipv4', $this->oldIpv4)->update(['service_ipv4' => $this->record->ipv4]);
            $this->record->cells()->where('edge_pool_id', $defaultSharedId)
                ->where(fn ($query) => $this->oldIpv6 === null ? $query->whereNull('service_ipv6') : $query->where('service_ipv6', $this->oldIpv6))
                ->update(['service_ipv6' => $this->record->ipv6]);
        }
        AuditLog::record(auth()->user(), 'edge.updated', $this->record, [], request()->ip());
        ReconcilePlatformDnsIdentity::dispatch()->afterCommit();
    }
}
