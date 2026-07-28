<?php

namespace App\Actions;

use App\Enums\DomainLifecycleState;
use App\Jobs\ReconcileDnsZone;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

class PromoteReadyEdgePlacements
{
    public static function execute(int $limit = 100): void
    {
        $edges = Edge::query()->readyForTraffic()->get();
        if ($edges->isEmpty()) {
            return;
        }
        $published = [];
        DomainEdgePlacement::query()->where('state', 'deploying')->whereNotNull('target_pool_id')
            ->orderBy('id')->limit(max(1, min(1000, $limit)))->get()
            ->each(function (DomainEdgePlacement $placement) use ($edges, &$published): void {
                $targetPool = $placement->targetPool()->first();
                if ($targetPool === null || ! $targetPool->isReady()) {
                    return;
                }
                $participants = $edges->filter(fn (Edge $edge): bool => DomainEdgeCell::query()
                    ->where('domain_id', $placement->domain_id)->where('edge_id', $edge->id)->whereNotNull('target_cell_id')->exists());
                if ($participants->isEmpty()) {
                    return;
                }
                $ready = $participants->every(function (Edge $edge) use ($placement): bool {
                    $cellsReady = DomainEdgeCell::query()->where('domain_id', $placement->domain_id)->where('edge_id', $edge->id)
                        ->where('desired_revision', $placement->desired_revision)->whereNotNull('target_cell_id')
                        ->whereHas('targetCell', fn ($query) => $query->where('status', 'ready')->where('drained', false))->count();
                    if ($cellsReady < $placement->targetPool->replicas_per_edge) {
                        return false;
                    }
                    $artifact = EdgeArtifact::query()->where('edge_id', $edge->id)->where('domain_id', $placement->domain_id)
                        ->where('revision', $placement->desired_revision)->latest('sequence')->first();

                    return $artifact !== null && $edge->active_sequence >= $artifact->sequence;
                });
                if (! $ready) {
                    return;
                }
                DB::transaction(function () use ($placement, &$published): void {
                    Domain::query()->lockForUpdate()->findOrFail($placement->domain_id);
                    $locked = DomainEdgePlacement::query()->lockForUpdate()->find($placement->id);
                    if ($locked === null || $locked->state !== 'deploying' || $locked->target_pool_id !== $placement->target_pool_id
                        || $locked->desired_revision !== $placement->desired_revision) {
                        return;
                    }
                    $previousPool = $locked->active_pool_id;
                    $targetPool = $locked->target_pool_id;
                    $moving = $previousPool !== null && $previousPool !== $targetPool;
                    $locked->update($moving ? [
                        'state' => 'draining', 'drain_after' => null,
                    ] : [
                        'active_pool_id' => $targetPool, 'target_pool_id' => null, 'state' => 'active', 'drain_after' => null,
                    ]);
                    DomainEdgeCell::query()->where('domain_id', $locked->domain_id)->where('desired_revision', $locked->desired_revision)
                        ->get()->each(function (DomainEdgeCell $row): void {
                            $movingCell = $row->active_cell_id !== null && $row->active_cell_id !== $row->target_cell_id;
                            $row->update($movingCell ? ['state' => 'draining'] : [
                                'active_cell_id' => $row->target_cell_id, 'target_cell_id' => null, 'state' => 'active', 'drain_after' => null,
                            ]);
                        });
                    Domain::query()->whereKey($locked->domain_id)->update(['active_edge_revision' => $locked->desired_revision]);
                    Operation::query()->where('type', 'edge.domain_reconcile')->whereIn('status', ['pending', 'running'])
                        ->where('input->domain_id', $locked->domain_id)->update([
                            'status' => $moving ? 'running' : 'succeeded',
                            'result' => [
                                'revision' => $locked->desired_revision,
                                'placement_state' => $locked->state,
                                'awaiting_dns_drain' => $moving,
                            ],
                            'error' => null, 'finished_at' => $moving ? null : now(),
                        ]);
                    AuditLog::record(null, 'edge.placement_target_ready', $locked, [
                        'active_pool_id' => $previousPool, 'target_pool_id' => $targetPool, 'state' => $locked->state,
                    ]);
                    $published[] = $locked->domain_id;
                });
            });
        foreach (array_unique($published) as $domainId) {
            $domain = Domain::query()->find($domainId);
            if ($domain?->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
                Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
                ReconcileDnsZone::dispatch($domain->id)->afterCommit();
            }
        }
    }
}
