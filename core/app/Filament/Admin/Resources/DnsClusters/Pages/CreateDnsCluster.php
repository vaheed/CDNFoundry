<?php

namespace App\Filament\Admin\Resources\DnsClusters\Pages;

use App\Filament\Admin\Resources\DnsClusters\DnsClusterResource;
use App\Jobs\TestDnsCluster;
use App\Models\AuditLog;
use App\Models\Operation;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateDnsCluster extends CreateRecord
{
    protected static string $resource = DnsClusterResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['enabled'] = false;

        return $data;
    }

    protected function afterCreate(): void
    {
        AuditLog::record(auth()->user(), 'dns.cluster_created', $this->record, ['name' => $this->record->name], request()->ip());
        $operation = Operation::query()->create([
            'actor_id' => auth()->id(),
            'type' => 'dns.cluster_test',
            'status' => 'pending',
            'input' => ['cluster_id' => $this->record->id],
        ]);
        AuditLog::record(auth()->user(), 'dns.cluster_test_requested', $this->record, [], request()->ip());
        TestDnsCluster::dispatch($operation->id)->afterCommit();
        Notification::make()->info()->title('Cluster saved disabled')
            ->body('Connection validation was queued. Enable the cluster after its health status becomes healthy.')->send();
    }
}
