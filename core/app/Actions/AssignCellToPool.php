<?php

namespace App\Actions;

use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Puts one cell into a pool and queues the global edge reconcile. Shared by the admin API and PoP enrollment. */
class AssignCellToPool
{
    public function handle(EdgePool $pool, EdgeCell $cell, ?User $actor, ?string $ip): Operation
    {
        abort_if($cell->edge_pool_id !== null && $cell->edge_pool_id !== $pool->id, 409, 'The cell is already assigned to another pool.');
        abort_if($cell->drained, 409, 'A drained cell cannot participate in a pool.');
        $operation = DB::transaction(function () use ($actor, $ip, $pool, $cell): Operation {
            $pool = EdgePool::query()->lockForUpdate()->findOrFail($pool->id);
            $cell = EdgeCell::query()->lockForUpdate()->findOrFail($cell->id);
            abort_if($cell->edge_pool_id !== null && $cell->edge_pool_id !== $pool->id, 409, 'The cell is already assigned to another pool.');
            abort_if($cell->drained, 409, 'A drained cell cannot participate in a pool.');
            $cell->update(['edge_pool_id' => $pool->id, 'status' => $cell->status === 'ready' ? 'ready' : 'assigned']);
            if ($pool->isSimpleAnycast()) {
                $endpoint = $pool->endpoints()->firstOrCreate(['edge_id' => $cell->edge_id], [
                    'revision' => 1, 'gateway_state' => 'pending', 'readiness_reason' => 'gateway_not_acknowledged',
                ]);
                if ($endpoint->wasRecentlyCreated) {
                    AuditLog::record($actor, 'edge.pool_endpoint_created', $endpoint, [
                        'edge_id' => $cell->edge_id, 'pool_id' => $pool->id, 'source' => 'cell_assignment',
                    ], $ip);
                }
            }
            $pool->update(['revision' => $pool->revision + 1]);
            $operation = Operation::query()->create(['actor_id' => $actor?->id, 'type' => 'edge.global_reconcile', 'status' => 'pending', 'input' => ['pool_id' => $pool->id, 'cell_id' => $cell->id]]);
            AuditLog::record($actor, 'edge.pool_cell_assigned', $cell, ['pool_id' => $pool->id, 'operation_id' => $operation->id], $ip);

            return $operation;
        });
        ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit();
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return $operation;
    }
}
