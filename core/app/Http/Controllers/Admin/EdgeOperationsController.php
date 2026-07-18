<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\EdgeTask;
use App\Models\Operation;
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

    public function routing(): JsonResponse
    {
        $edges = Edge::query()->where('enabled', true)->where('drained', false)
            ->whereNull('identity_revoked_at')->whereNotNull('registered_at')
            ->where('last_heartbeat_at', '>=', now()->subSeconds((int) config('edge.heartbeat_fresh_seconds')))
            ->where('capacity->listener_ready', true)
            ->orderBy('country_code')->orderBy('continent_code')->orderBy('id')->get(['id', 'name', 'country_code', 'continent_code', 'ipv4', 'ipv6', 'last_heartbeat_at']);

        return response()->json(['data' => [
            'generated_at' => now()->toIso8601String(), 'freshness_seconds' => (int) config('edge.heartbeat_fresh_seconds'),
            'countries' => $edges->groupBy('country_code')->map->pluck('id')->all(),
            'continents' => $edges->groupBy('continent_code')->map->pluck('id')->all(), 'global' => $edges,
        ]]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $operation = Operation::query()->where('type', 'edge.global_reconcile')->whereIn('status', ['pending', 'running'])->first()
            ?? Operation::query()->create(['actor_id' => $request->user()->id, 'type' => 'edge.global_reconcile', 'status' => 'running', 'started_at' => now(), 'input' => []]);
        $count = 0;
        Domain::query()->whereHas('dnsRecords', fn ($query) => $query->where('mode', 'proxied'))->orderBy('id')->chunkById(500, function ($domains) use (&$count): void {
            foreach ($domains as $domain) {
                ReconcileEdgeDomain::dispatch($domain->id);
                $count++;
            }
        });
        $operation->update(['status' => 'succeeded', 'result' => ['domains_dispatched' => $count], 'finished_at' => now()]);

        return response()->json(['data' => ['operation_id' => $operation->id, 'domains_dispatched' => $count]], 202);
    }

    public function cells()
    {
        return response()->json(['data' => EdgeCell::query()->with(['edge', 'pool'])->orderBy('id')->cursorPaginate(100)]);
    }

    public function cell(EdgeCell $cell): JsonResponse
    {
        return response()->json(['data' => $cell->load(['edge', 'pool'])]);
    }

    public function cellAction(Request $request, EdgeCell $cell, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['drain', 'undrain', 'restart'], true), 404);
        if ($action !== 'restart') {
            $cell->update(['drained' => $action === 'drain', 'status' => $action === 'drain' ? 'drained' : 'pending']);
        }
        $task = EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $cell->edge_id, 'type' => 'cell_'.$action, 'status' => 'pending', 'payload' => ['cell_id' => $cell->id, 'cell_name' => $cell->name]]);
        AuditLog::record($request->user(), 'edge.cell_'.$action, $cell, ['task_id' => $task->id], $request->ip());

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
        $operation = DB::transaction(function () use ($request, $domain, $pool): Operation {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
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
