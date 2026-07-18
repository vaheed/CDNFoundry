<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Support\EdgeCellAddressData;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EdgeOperationsController extends Controller
{
    public function deployments()
    {
        return response()->json(['data' => DomainEdgePlacement::query()->with(['domain', 'activePool', 'targetPool'])->orderBy('id')->cursorPaginate(100)]);
    }

    public function routing(Request $request): JsonResponse
    {
        $page = Edge::query()->readyForTraffic()
            ->orderBy('country_code')->orderBy('continent_code')->orderBy('id')
            ->cursorPaginate(250, ['id', 'name', 'country_code', 'continent_code', 'ipv4', 'ipv6', 'last_heartbeat_at']);
        $edges = $page->getCollection();

        return response()->json(['data' => [
            'generated_at' => now()->toIso8601String(), 'freshness_seconds' => app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds'),
            'countries' => $edges->groupBy('country_code')->map->pluck('id')->all(),
            'continents' => $edges->groupBy('continent_code')->map->pluck('id')->all(), 'global' => $edges,
            'next_cursor' => $page->nextCursor()?->encode(),
        ]]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $operation = Operation::query()->where('type', 'edge.global_reconcile')->whereIn('status', ['pending', 'running'])->first()
            ?? Operation::query()->create(['actor_id' => $request->user()->id, 'type' => 'edge.global_reconcile', 'status' => 'pending', 'input' => []]);
        AuditLog::record($request->user(), 'edge.global_reconcile_requested', $operation, [], $request->ip());
        ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    public function cells()
    {
        return response()->json(['data' => EdgeCell::query()->with(['edge', 'pool'])->orderBy('id')->cursorPaginate(100)]);
    }

    public function cell(EdgeCell $cell): JsonResponse
    {
        return response()->json(['data' => $cell->load(['edge', 'pool'])]);
    }

    public function updateCell(Request $request, EdgeCell $cell): JsonResponse
    {
        $data = EdgeCellAddressData::validate($cell, $request->all());
        DB::transaction(function () use ($request, $cell, $data): void {
            $cell->update($data);
            AuditLog::record($request->user(), 'edge.cell_addresses_updated', $cell, ['fields' => array_keys($data)], $request->ip());
        });
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();

        return response()->json(['data' => $cell->refresh()->load(['edge', 'pool'])]);
    }

    public function cellAction(Request $request, EdgeCell $cell, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['drain', 'undrain', 'restart'], true), 404);
        abort_if($action !== 'drain' && $cell->service_ipv4 === null, 409, 'Configure the cell service addresses before making it available.');
        $task = DB::transaction(function () use ($action, $cell, $request): EdgeTask {
            if ($action !== 'restart') {
                $cell->update(['drained' => $action === 'drain', ...($action === 'undrain' ? ['status' => 'pending'] : [])]);
            }
            $task = EdgeTask::query()->where('edge_id', $cell->edge_id)->where('type', 'cell_'.$action)
                ->where('status', 'pending')->where('payload->cell_id', $cell->id)->first()
                ?? EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $cell->edge_id, 'type' => 'cell_'.$action, 'status' => 'pending', 'payload' => ['cell_id' => $cell->id, 'cell_name' => $cell->name]]);
            AuditLog::record($request->user(), 'edge.cell_'.$action, $cell, ['task_id' => $task->id], $request->ip());

            return $task;
        });
        if ($action !== 'restart') {
            ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
        }

        return response()->json(['data' => ['task_id' => $task->id, 'status' => 'pending']], 202);
    }

    public function drainCell(Request $request, EdgeCell $cell): JsonResponse
    {
        return $this->cellAction($request, $cell, 'drain');
    }

    public function undrainCell(Request $request, EdgeCell $cell): JsonResponse
    {
        return $this->cellAction($request, $cell, 'undrain');
    }

    public function restartCell(Request $request, EdgeCell $cell): JsonResponse
    {
        return $this->cellAction($request, $cell, 'restart');
    }

    public function isolation(Domain $domain): JsonResponse
    {
        return response()->json(['data' => $domain->edgePlacement?->load(['activePool', 'targetPool'])]);
    }

    public function move(Request $request, Domain $domain): JsonResponse
    {
        $data = $request->validate(['pool_id' => ['required', 'integer', 'exists:edge_pools,id']]);
        $pool = EdgePool::query()->whereKey($data['pool_id'])->where('enabled', true)->firstOrFail();
        abort_unless($domain->dnsRecords()->where('mode', 'proxied')->exists(), 409, 'Only a proxied domain can be moved.');
        if ($pool->kind === 'dedicated') {
            abort_if(DomainEdgePlacement::query()->where('domain_id', '!=', $domain->id)
                ->where(fn ($query) => $query->where('active_pool_id', $pool->id)->orWhere('target_pool_id', $pool->id))->exists(), 409, 'A dedicated pool can be assigned to only one domain.');
        }
        $operation = DB::transaction(function () use ($request, $domain, $pool): Operation {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $current = DomainEdgePlacement::query()->where('domain_id', $locked->id)->lockForUpdate()->first();
            if ($current?->state === 'active' && $current->active_pool_id === $pool->id) {
                return Operation::coalesceDomain('edge.domain_reconcile', $locked->id, $request->user()->id);
            }
            $locked->update(['revision' => $locked->revision + 1]);
            DomainEdgePlacement::query()->updateOrCreate(['domain_id' => $locked->id], [
                'target_pool_id' => $pool->id, 'desired_revision' => $locked->revision, 'state' => 'deploying', 'last_error' => null,
            ]);
            $operation = Operation::coalesceDomain('edge.domain_reconcile', $locked->id, $request->user()->id);
            AuditLog::record($request->user(), 'edge.domain_move_requested', $locked, ['target_pool_id' => $pool->id, 'revision' => $locked->revision], $request->ip());

            return $operation;
        });
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'target_pool_id' => $pool->id]], 202);
    }
}
