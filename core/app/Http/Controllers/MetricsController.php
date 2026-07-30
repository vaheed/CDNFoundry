<?php

namespace App\Http\Controllers;

use App\Models\DnsDeployment;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePoolEndpoint;
use App\Models\FleetRollout;
use App\Models\Operation;
use App\Models\TlsCertificate;
use App\Support\PlatformSettings;
use App\Support\SystemHealth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetricsController extends Controller
{
    public function __invoke(Request $request, SystemHealth $health): Response
    {
        $configured = (string) config('services.metrics.token');
        $tokenFile = config('services.metrics.token_file');
        if ($configured === '' && is_string($tokenFile) && is_readable($tokenFile)) {
            $configured = trim((string) file_get_contents($tokenFile));
        }
        abort_if($configured === '' || ! hash_equals($configured, (string) $request->bearerToken()), 404);
        $lines = ['# HELP cdnfoundry_component_health Component health (healthy=1).', '# TYPE cdnfoundry_component_health gauge'];
        foreach ($health->components() as $name => $component) {
            $lines[] = sprintf('cdnfoundry_component_health{component="%s",status="%s"} %d', $name, $component['status'], $component['status'] === 'healthy' ? 1 : 0);
        }
        foreach ($health->queues() as $queue => $state) {
            if ($state['depth'] !== null) {
                $lines[] = sprintf('cdnfoundry_queue_depth{queue="%s"} %d', $queue, $state['depth']);
            }
            if ($state['oldest_job_age_seconds'] !== null || $state['depth'] === 0) {
                $lines[] = sprintf('cdnfoundry_queue_oldest_job_age_seconds{queue="%s"} %d', $queue, $state['oldest_job_age_seconds'] ?? 0);
            }
        }
        $lines[] = 'cdnfoundry_operations_failed '.Operation::query()->where('status', 'failed')->count();
        $lines[] = 'cdnfoundry_dns_deployments_drifted '.DnsDeployment::query()->whereIn('status', ['pending', 'failed'])->count();
        $lines[] = 'cdnfoundry_edges_stale '.Edge::query()->where('enabled', true)->where(fn ($query) => $query->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds'))))->count();
        $enabledEdges = Edge::query()->where('enabled', true)->get(['capacity']);
        $lines[] = 'cdnfoundry_edge_gateways_unready '.$enabledEdges->filter(fn (Edge $edge): bool => ! ($edge->capacity['gateway']['ready'] ?? false))->count();
        if ($enabledEdges->every(fn (Edge $edge): bool => is_numeric($edge->capacity['gateway']['errors'] ?? null))) {
            $lines[] = 'cdnfoundry_edge_gateway_errors_total '.$enabledEdges->sum(fn (Edge $edge): int => (int) $edge->capacity['gateway']['errors']);
        }
        if ($enabledEdges->every(fn (Edge $edge): bool => is_numeric($edge->capacity['gateway']['candidate_rejections'] ?? null))) {
            $lines[] = 'cdnfoundry_edge_gateway_candidate_rejections_total '.$enabledEdges->sum(fn (Edge $edge): int => (int) $edge->capacity['gateway']['candidate_rejections']);
        }
        Edge::query()->where('enabled', true)->orderBy('id')->limit(200)->get()->each(function (Edge $edge) use (&$lines): void {
            $gateway = $edge->capacity['gateway'] ?? [];
            $revision = (int) ($gateway['active_revision'] ?? 0);
            $status = ($gateway['ready'] ?? false) ? 'healthy' : ($edge->last_heartbeat_at?->gte(now()->subSeconds(30)) ? 'degraded' : 'unavailable');
            $lines[] = sprintf('cdnfoundry_edge_runtime_state{edge="%s",component="gateway",status="%s",revision="%d"} 1', $edge->id, $status, $revision);
            $lines[] = sprintf('cdnfoundry_edge_runtime_version_drift{edge="%s"} %d', $edge->id, $edge->desired_runtime_versions !== null && $edge->runtime_versions !== $edge->desired_runtime_versions ? 1 : 0);
            foreach ([
                'ready' => 'ready',
                'active_revision' => 'active_revision',
                'routes' => 'routes',
                'listeners' => 'listeners',
                'connections_active' => 'connections_active',
                'connections_accepted' => 'connections_accepted_total',
                'connections_rejected' => 'connections_rejected_total',
                'errors' => 'errors_total',
                'activations' => 'activations_total',
                'candidate_rejections' => 'candidate_rejections_total',
            ] as $key => $metric) {
                if (! array_key_exists($key, $gateway)) {
                    continue;
                }
                $value = $key === 'ready' ? (($gateway[$key] ?? false) ? 1 : 0) : (int) ($gateway[$key] ?? 0);
                $lines[] = sprintf('cdnfoundry_edge_gateway_%s{edge="%s"} %d', $metric, $edge->id, $value);
            }
        });
        EdgeCell::query()->with(['edge', 'pool'])->orderBy('id')->limit(1000)->get()->each(function (EdgeCell $cell) use (&$lines): void {
            $capacity = $cell->capacity ?? [];
            $pool = $cell->pool?->id ?? 0;
            $revision = (int) ($cell->edge->capacity['gateway']['active_revision'] ?? 0);
            $lines[] = sprintf('cdnfoundry_cell_runtime_state{edge="%s",pool="%s",cell="%s",status="%s",revision="%d"} 1', $cell->edge_id, $pool, $cell->name, $cell->status, $revision);
            foreach ([['cache_usage', 'cache_limit', 'cache'], ['memory_usage', 'memory_limit', 'memory'], ['active_connections', 'connection_limit', 'connections']] as [$used, $limit, $resource]) {
                if (is_numeric($capacity[$used] ?? null) && is_numeric($capacity[$limit] ?? null)) {
                    $lines[] = sprintf('cdnfoundry_cell_capacity_ratio{edge="%s",pool="%s",cell="%s",resource="%s"} %.6f', $cell->edge_id, $pool, $cell->name, $resource, (float) $capacity[$limit] > 0 ? (float) $capacity[$used] / (float) $capacity[$limit] : 0);
                }
            }
        });
        EdgePoolEndpoint::query()->orderBy('id')->limit(1000)->get()->each(function (EdgePoolEndpoint $endpoint) use (&$lines): void {
            $lines[] = sprintf('cdnfoundry_pool_endpoint_state{edge="%s",pool="%s",endpoint="%s",status="%s",revision="%d"} 1', $endpoint->edge_id, $endpoint->edge_pool_id, $endpoint->id, $endpoint->gateway_state, $endpoint->revision);
            $lines[] = sprintf('cdnfoundry_pool_endpoint_revision_mismatch{edge="%s",pool="%s",endpoint="%s"} %d', $endpoint->edge_id, $endpoint->edge_pool_id, $endpoint->id, $endpoint->gateway_revision < $endpoint->revision ? 1 : 0);
        });
        $lines[] = 'cdnfoundry_fleet_rollouts_paused '.FleetRollout::query()->where('status', 'paused')->count();
        $lines[] = 'cdnfoundry_tls_certificates_expiring '.TlsCertificate::query()->where('status', 'active')->where('expires_at', '<=', now()->addDays((int) config('services.acme.expiry_alert_days')))->count();

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
