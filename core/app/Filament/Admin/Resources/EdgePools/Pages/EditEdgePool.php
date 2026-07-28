<?php

namespace App\Filament\Admin\Resources\EdgePools\Pages;

use App\Filament\Admin\Resources\EdgePools\EdgePoolResource;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Operation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditEdgePool extends EditRecord
{
    protected static string $resource = EdgePoolResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['replicas_per_edge'] ?? $this->record->replicas_per_edge) > 1 && ! in_array($this->record->kind, ['reserved', 'dedicated'], true)) {
            throw ValidationException::withMessages(['replicas_per_edge' => 'Replicated placement is limited to reserved and dedicated pools.']);
        }
        if (($data['name'] ?? $this->record->name) !== $this->record->name && $this->record->cells()->exists()) {
            throw ValidationException::withMessages(['name' => 'Pool runtime names are immutable after cells have been provisioned.']);
        }
        $data['revision'] = $this->record->revision + 1;

        return $data;
    }

    protected function afterSave(): void
    {
        AuditLog::record(auth()->user(), 'edge.pool_updated', $this->record, [], request()->ip());
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
        if ($this->record->wasChanged(['minimum_ready_cells', 'replicas_per_edge', 'maximum_domains_per_cell'])) {
            $operation = Operation::query()->create([
                'actor_id' => auth()->id(), 'type' => 'edge.global_reconcile', 'status' => 'pending',
                'input' => ['pool_id' => $this->record->id, 'reason' => 'pool_policy_changed'],
            ]);
            ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit();
        }
    }
}
