<?php

namespace App\Support;

use App\Models\ControlNode;
use App\Models\DnsCluster;
use App\Models\Edge;
use App\Models\EdgeCell;

/**
 * The fleet as nodes and links, for the topology view and its API. Bounded: at most MAX_EDGES edges and
 * MAX_CELLS_PER_EDGE cells per edge are drawn, and the rest are counted in the edge's detail.
 *
 * Status is one of healthy, degraded, failed, pending, disabled, unknown. A link takes the worse status of its ends.
 */
class TopologyGraph
{
    public const MAX_EDGES = 200;

    public const MAX_CELLS_PER_EDGE = 8;

    private const SEVERITY = ['healthy' => 0, 'unknown' => 1, 'disabled' => 1, 'pending' => 2, 'degraded' => 3, 'failed' => 4];

    /** @param array<string, array<string, mixed>> $components output of SystemHealth::components() */
    public function build(array $components): array
    {
        $nodes = [];
        $links = [];
        $heartbeatSeconds = app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds');

        $controls = ControlNode::query()->orderBy('role')->orderBy('name')->limit(4)->get();
        $controlIds = [];
        if ($controls->isEmpty()) {
            $nodes[] = $this->node('control:self', 'control', 'Control plane', 'healthy', 'Single node');
            $controlIds[] = 'control:self';
        }
        foreach ($controls as $control) {
            $stale = $control->reported_at === null || $control->reported_at->lt(now()->subMinutes(5));
            $id = 'control:'.$control->id;
            $nodes[] = $this->node($id, 'control', $control->name, $stale ? 'failed' : 'healthy', ucfirst($control->role).($control->replication_lag_seconds === null ? '' : ", lag {$control->replication_lag_seconds}s"));
            $controlIds[] = $id;
        }
        if (count($controlIds) === 2) {
            $links[] = ['from' => $controlIds[0], 'to' => $controlIds[1]];
        }
        $primary = $controlIds[0];

        foreach (DnsCluster::query()->orderBy('name')->limit(50)->get() as $cluster) {
            $id = 'dns:'.$cluster->id;
            $status = ! $cluster->enabled ? 'disabled' : ($cluster->last_health_status === 'healthy' ? 'healthy' : ($cluster->last_health_status === null ? 'unknown' : 'failed'));
            $nodes[] = $this->node($id, 'dns', $cluster->name, $status, $cluster->location);
            $links[] = ['from' => $primary, 'to' => $id];
        }

        $edges = Edge::query()->withCount('cells')->orderBy('name')->limit(self::MAX_EDGES)->get();
        $cells = EdgeCell::query()->whereIn('edge_id', $edges->pluck('id'))->orderBy('edge_id')->orderBy('slot')->get()->groupBy('edge_id');
        foreach ($edges as $edge) {
            $id = 'edge:'.$edge->id;
            $stale = $edge->last_heartbeat_at === null || $edge->last_heartbeat_at->lt(now()->subSeconds($heartbeatSeconds));
            $status = match (true) {
                ! $edge->enabled => 'disabled',
                $stale => 'failed',
                $edge->drained => 'pending',
                default => 'healthy',
            };
            $nodes[] = $this->node($id, 'edge', $edge->name, $status, $edge->country_code.($edge->drained ? ', drained' : '').($edge->cells_count > self::MAX_CELLS_PER_EDGE ? ", {$edge->cells_count} cells" : ''));
            $links[] = ['from' => $primary, 'to' => $id];
            foreach (($cells[$edge->id] ?? collect())->take(self::MAX_CELLS_PER_EDGE) as $cell) {
                $cellStatus = match (true) {
                    $cell->drained => 'disabled',
                    $cell->status === 'ready' => 'healthy',
                    in_array($cell->status, ['unassigned', 'assigned'], true) => 'pending',
                    default => 'degraded',
                };
                $nodes[] = $this->node('cell:'.$cell->id, 'cell', $cell->name, $edge->enabled ? $cellStatus : 'disabled', $cell->status);
                $links[] = ['from' => $id, 'to' => 'cell:'.$cell->id];
            }
        }

        foreach (['vector' => 'Vector', 'clickhouse' => 'ClickHouse'] as $key => $label) {
            $state = $components[$key]['status'] ?? null;
            $nodes[] = $this->node('telemetry:'.$key, 'telemetry', $label, match ($state) {
                'healthy' => 'healthy',
                null => 'unknown',
                default => 'failed',
            }, 'Telemetry');
        }
        $links[] = ['from' => 'telemetry:vector', 'to' => 'telemetry:clickhouse'];
        foreach ($edges->take(1) as $edge) {
            $links[] = ['from' => 'edge:'.$edge->id, 'to' => 'telemetry:vector'];
        }

        $byId = collect($nodes)->keyBy('id');
        $links = array_map(function (array $link) use ($byId): array {
            $a = $byId[$link['from']]['status'] ?? 'unknown';
            $b = $byId[$link['to']]['status'] ?? 'unknown';

            return $link + ['status' => self::SEVERITY[$a] >= self::SEVERITY[$b] ? $a : $b];
        }, $links);

        return ['nodes' => $nodes, 'links' => $links, 'truncated' => Edge::query()->count() > self::MAX_EDGES];
    }

    private function node(string $id, string $kind, string $label, string $status, ?string $detail): array
    {
        return ['id' => $id, 'kind' => $kind, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
