<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionEdgePoolCells;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EdgePoolController extends Controller
{
    public function index()
    {
        return response()->json(['data' => EdgePool::query()->orderBy('id')->cursorPaginate(100)]);
    }

    public function show(EdgePool $pool): JsonResponse
    {
        return response()->json(['data' => $pool]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:edge_pools'], 'kind' => ['required', 'in:shared,reserved,quarantine,dedicated'],
            'minimum_ready_cells' => ['sometimes', 'integer', 'between:1,32'], 'replicas_per_edge' => ['sometimes', 'integer', 'between:1,3'],
            'maximum_domains_per_cell' => ['sometimes', 'integer', 'between:1,100000'],
        ]);
        abort_if(($data['replicas_per_edge'] ?? 1) > 1 && ! in_array($data['kind'], ['reserved', 'dedicated'], true), 422, 'Replicated placement is limited to reserved and dedicated pools.');
        abort_if(EdgePool::query()->count() >= 32, 409, 'The deployment has reached the bounded 32-pool limit.');
        [$pool, $operation] = DB::transaction(function () use ($data, $request): array {
            $pool = EdgePool::query()->create([...$data, 'enabled' => false]);
            $operation = Operation::query()->create([
                'actor_id' => $request->user()->id, 'type' => 'edge.pool_provision', 'status' => 'pending',
                'input' => ['pool_id' => $pool->id],
            ]);
            AuditLog::record($request->user(), 'edge.pool_created', $pool, ['kind' => $pool->kind], $request->ip());

            return [$pool, $operation];
        });
        ProvisionEdgePoolCells::dispatch($pool->id, $operation->id)->afterCommit();

        return response()->json(['data' => ['pool' => $pool, 'operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    public function update(Request $request, EdgePool $pool): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('edge_pools')->ignore($pool)],
            'minimum_ready_cells' => ['sometimes', 'integer', 'between:1,32'], 'replicas_per_edge' => ['sometimes', 'integer', 'between:1,3'],
            'maximum_domains_per_cell' => ['sometimes', 'integer', 'between:1,100000'],
        ]);
        abort_if(isset($data['replicas_per_edge']) && $data['replicas_per_edge'] > 1 && ! in_array($pool->kind, ['reserved', 'dedicated'], true), 422, 'Replicated placement is limited to reserved and dedicated pools.');
        abort_if(isset($data['name']) && $data['name'] !== $pool->name && $pool->cells()->exists(), 409, 'Pool runtime names are immutable after cells have been provisioned.');
        $placementPolicyChanged = array_intersect(array_keys($data), ['minimum_ready_cells', 'replicas_per_edge', 'maximum_domains_per_cell']) !== [];
        $operation = DB::transaction(function () use ($pool, $data, $placementPolicyChanged, $request): ?Operation {
            $pool->update([...$data, 'revision' => $pool->revision + 1]);
            AuditLog::record($request->user(), 'edge.pool_updated', $pool, ['fields' => array_keys($data)], $request->ip());

            return $placementPolicyChanged ? Operation::query()->create([
                'actor_id' => $request->user()->id, 'type' => 'edge.global_reconcile', 'status' => 'pending',
                'input' => ['pool_id' => $pool->id, 'reason' => 'pool_policy_changed'],
            ]) : null;
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
        if ($operation !== null) {
            ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit();

            return response()->json(['data' => ['pool' => $pool->refresh(), 'operation_id' => $operation->id, 'status' => $operation->status]], 202);
        }

        return response()->json(['data' => $pool->refresh()]);
    }

    public function assignCell(Request $request, EdgePool $pool, EdgeCell $cell): JsonResponse
    {
        abort_if($cell->edge_pool_id !== null && $cell->edge_pool_id !== $pool->id, 409, 'The cell is already assigned to another pool.');
        abort_if($cell->drained, 409, 'A drained cell cannot participate in a pool.');
        [$operation] = DB::transaction(function () use ($request, $pool, $cell): array {
            $cell->update(['edge_pool_id' => $pool->id, 'status' => $cell->status === 'ready' ? 'ready' : 'assigned']);
            $pool->update(['revision' => $pool->revision + 1]);
            $operation = Operation::query()->create(['actor_id' => $request->user()->id, 'type' => 'edge.global_reconcile', 'status' => 'pending', 'input' => ['pool_id' => $pool->id, 'cell_id' => $cell->id]]);
            AuditLog::record($request->user(), 'edge.pool_cell_assigned', $cell, ['pool_id' => $pool->id, 'operation_id' => $operation->id], $request->ip());

            return [$operation];
        });
        ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'cell_id' => $cell->id, 'pool_id' => $pool->id]], 202);
    }

    public function unassignCell(Request $request, EdgePool $pool, EdgeCell $cell): JsonResponse
    {
        abort_unless($cell->edge_pool_id === $pool->id, 404);
        abort_if(DomainEdgeCell::query()->where(fn ($query) => $query->where('active_cell_id', $cell->id)->orWhere('target_cell_id', $cell->id))->exists(), 409, 'Move all domain placements away from the cell before unassigning it.');
        abort_if($pool->cells()->where('edge_id', $cell->edge_id)->where('id', '!=', $cell->id)->count() < $pool->minimum_ready_cells, 409, 'The assignment is required by the pool minimum-ready-cell policy on this edge.');
        DB::transaction(function () use ($request, $pool, $cell): void {
            $cell->update(['edge_pool_id' => null, 'status' => 'unassigned', 'service_ipv4' => null, 'service_ipv6' => null]);
            $pool->update(['revision' => $pool->revision + 1]);
            AuditLog::record($request->user(), 'edge.pool_cell_unassigned', $cell, ['pool_id' => $pool->id], $request->ip());
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(null, 204);
    }

    public function state(Request $request, EdgePool $pool, string $state): JsonResponse
    {
        if ($state === 'enable') {
            $incomplete = Edge::query()->where('enabled', true)->get()->contains(fn (Edge $edge): bool => $edge->cells()->where('edge_pool_id', $pool->id)->whereNotNull('service_ipv4')->count() < $pool->minimum_ready_cells
            );
            abort_if($incomplete, 409, 'Every enabled edge requires an IPv4 service address before the pool can be enabled.');
        } else {
            abort_if(DomainEdgePlacement::query()->where('active_pool_id', $pool->id)->orWhere('target_pool_id', $pool->id)->exists(), 409, 'A pool with active or target placements cannot be disabled.');
        }
        DB::transaction(function () use ($request, $pool, $state): void {
            $pool->update(['enabled' => $state === 'enable', 'revision' => $pool->revision + 1]);
            AuditLog::record($request->user(), 'edge.pool_'.$state.'d', $pool, ['revision' => $pool->revision], $request->ip());
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(['data' => $pool]);
    }

    public function enable(Request $request, EdgePool $pool): JsonResponse
    {
        return $this->state($request, $pool, 'enable');
    }

    public function disable(Request $request, EdgePool $pool): JsonResponse
    {
        return $this->state($request, $pool, 'disable');
    }
}
