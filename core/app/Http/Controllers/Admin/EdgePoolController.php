<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionEdgePoolCells;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
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
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:edge_pools'], 'kind' => ['required', 'in:shared,quarantine,dedicated']]);
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
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100', Rule::unique('edge_pools')->ignore($pool)]]);
        abort_if(isset($data['name']) && $data['name'] !== $pool->name && $pool->cells()->exists(), 409, 'Pool runtime names are immutable after cells have been provisioned.');
        DB::transaction(function () use ($pool, $data, $request): void {
            $pool->update([...$data, 'revision' => $pool->revision + 1]);
            AuditLog::record($request->user(), 'edge.pool_updated', $pool, ['fields' => array_keys($data)], $request->ip());
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(['data' => $pool->refresh()]);
    }

    public function state(Request $request, EdgePool $pool, string $state): JsonResponse
    {
        if ($state === 'enable') {
            $enabledEdges = Edge::query()->where('enabled', true)->count();
            $incomplete = $pool->cells()->whereHas('edge', fn ($query) => $query->where('enabled', true))->count() !== $enabledEdges
                || $pool->cells()->whereHas('edge', fn ($query) => $query->where('enabled', true))->whereNull('service_ipv4')->exists()
                || $pool->cells()->whereNull('service_ipv6')->whereHas('edge', fn ($query) => $query->where('enabled', true)->whereNotNull('ipv6'))->exists();
            abort_if($incomplete, 409, 'Every enabled edge requires IPv4 and declared IPv6 service addresses before the pool can be enabled.');
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
