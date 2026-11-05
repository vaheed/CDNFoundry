<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CachePurge;
use App\Models\DnsDeployment;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use App\Models\EmergencyMode;
use App\Models\FleetRollout;
use App\Models\Operation;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Support\ClientNetwork;
use App\Support\ControlPlane;
use App\Support\ControlPlaneMetrics;
use App\Support\PlatformSettings;
use App\Support\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
        $unhealthy = 0;
        foreach ($health->components() as $name => $component) {
            $lines[] = sprintf('cdnfoundry_component_health{component="%s",status="%s"} %d', $name, $component['status'], $component['status'] === 'healthy' ? 1 : 0);
            $unhealthy += $component['status'] === 'healthy' ? 0 : 1;
        }
        foreach ($health->queues() as $queue => $state) {
            if ($state['depth'] !== null) {
                $lines[] = sprintf('cdnfoundry_queue_depth{queue="%s"} %d', $queue, $state['depth']);
            }
            if ($state['oldest_job_age_seconds'] !== null || $state['depth'] === 0) {
                $lines[] = sprintf('cdnfoundry_queue_oldest_job_age_seconds{queue="%s"} %d', $queue, $state['oldest_job_age_seconds'] ?? 0);
            }
        }
        try {
            $ha = ControlPlane::status();
            foreach (['none', 'ready', 'degraded'] as $state) {
                $lines[] = sprintf('cdnfoundry_control_ha_state{state="%s"} %d', $state, $ha['ha'] === $state ? 1 : 0);
            }
            $lines[] = 'cdnfoundry_control_epoch '.$ha['epoch'];
            $lines[] = 'cdnfoundry_control_edges_missing_addresses '.$ha['edges_missing_addresses'];
            if ($ha['ha'] !== 'none') {
                $lines[] = 'cdnfoundry_control_replication_lag_seconds '.($ha['replication_lag_seconds'] ?? -1);
                $lines[] = 'cdnfoundry_control_valkey_replica_linked '.(in_array('valkey_replication_broken', $ha['reasons'], true) ? 0 : 1);
                $lines[] = 'cdnfoundry_control_fingerprint_mismatch '.(in_array('secret_fingerprint_mismatch', $ha['reasons'], true) ? 1 : 0);
                $lines[] = 'cdnfoundry_control_standby_unreachable '.(in_array('standby_unreachable', $ha['reasons'], true) ? 1 : 0);
            }
        } catch (Throwable) {
        }
        // Only failures inside the window count, so the alert resolves once the window passes.
        $failureSince = now()->subMinutes(app(PlatformSettings::class)->integer('operations', 'failed_operations_window_minutes'));
        $lines[] = '# HELP cdnfoundry_operations_failed_recent Operations that failed inside the failed-operations window.';
        $lines[] = '# TYPE cdnfoundry_operations_failed_recent gauge';
        $lines[] = 'cdnfoundry_operations_failed_recent '.Operation::query()->where('status', 'failed')
            ->where(fn ($query) => $query->where('finished_at', '>=', $failureSince)->orWhere(fn ($query) => $query->whereNull('finished_at')->where('updated_at', '>=', $failureSince)))->count();
        $lines[] = '# HELP cdnfoundry_failed_jobs_recent Queue jobs that failed inside the failed-operations window.';
        $lines[] = '# TYPE cdnfoundry_failed_jobs_recent gauge';
        $lines[] = 'cdnfoundry_failed_jobs_recent '.DB::table('failed_jobs')->where('failed_at', '>=', $failureSince)->count();
        $lines = [...$lines, ...$this->propagationLines(), ...ControlPlaneMetrics::lines()];
        $staleEdges = Edge::query()->where('enabled', true)->where(fn ($query) => $query->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds'))))->count();
        // The one sentence the home dashboard shows, built where every count is known. It names what is wrong.
        $problems = array_values(array_filter([
            $unhealthy > 0 ? sprintf('%d %s unhealthy', $unhealthy, $unhealthy === 1 ? 'component' : 'components') : null,
            $staleEdges > 0 ? sprintf('%d %s stale', $staleEdges, $staleEdges === 1 ? 'edge' : 'edges') : null,
        ]));
        $lines[] = '# HELP cdnfoundry_status_summary One line naming what is wrong; the label is the sentence.';
        $lines[] = '# TYPE cdnfoundry_status_summary gauge';
        $lines[] = sprintf('cdnfoundry_status_summary{state="%s",summary="%s"} 1', $problems === [] ? 'healthy' : 'degraded', $problems === [] ? 'Healthy: every component reports in' : 'Degraded: '.implode(', ', $problems));
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
            $lines[] = sprintf('cdnfoundry_edge_runtime_state{edge="%s",pop="%s",component="gateway",status="%s",revision="%d"} 1', $edge->id, $this->label($edge->name), $status, $revision);
            $lines[] = sprintf('cdnfoundry_edge_runtime_version_drift{edge="%s",pop="%s"} %d', $edge->id, $this->label($edge->name), $edge->desired_runtime_versions !== null && $edge->runtime_versions !== $edge->desired_runtime_versions ? 1 : 0);
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
                $lines[] = sprintf('cdnfoundry_edge_gateway_%s{edge="%s",pop="%s"} %d', $metric, $edge->id, $this->label($edge->name), $value);
            }
        });
        $popNames = Edge::query()->pluck('name', 'id')->all();
        EdgeCell::query()->with(['edge', 'pool'])->orderBy('id')->limit(1000)->get()->each(function (EdgeCell $cell) use (&$lines, $popNames): void {
            $capacity = $cell->capacity ?? [];
            $pool = $cell->pool?->id ?? 0;
            $revision = (int) ($cell->edge->capacity['gateway']['active_revision'] ?? 0);
            $lines[] = sprintf('cdnfoundry_cell_runtime_state{edge="%s",pop="%s",pool="%s",cell="%s",status="%s",revision="%d"} 1', $cell->edge_id, $this->label($popNames[$cell->edge_id] ?? 'unknown'), $pool, $cell->name, $cell->status, $revision);
            foreach ([['cache_usage', 'cache_limit', 'cache'], ['memory_usage', 'memory_limit', 'memory'], ['active_connections', 'connection_limit', 'connections']] as [$used, $limit, $resource]) {
                if (is_numeric($capacity[$used] ?? null) && is_numeric($capacity[$limit] ?? null)) {
                    $lines[] = sprintf('cdnfoundry_cell_capacity_ratio{edge="%s",pop="%s",pool="%s",cell="%s",resource="%s"} %.6f', $cell->edge_id, $this->label($popNames[$cell->edge_id] ?? 'unknown'), $pool, $cell->name, $resource, (float) $capacity[$limit] > 0 ? (float) $capacity[$used] / (float) $capacity[$limit] : 0);
                }
            }
        });
        EdgePoolEndpoint::query()->orderBy('id')->limit(1000)->get()->each(function (EdgePoolEndpoint $endpoint) use (&$lines): void {
            $lines[] = sprintf('cdnfoundry_pool_endpoint_state{edge="%s",pool="%s",endpoint="%s",status="%s",revision="%d"} 1', $endpoint->edge_id, $endpoint->edge_pool_id, $endpoint->id, $endpoint->gateway_state, $endpoint->revision);
            $lines[] = sprintf('cdnfoundry_pool_endpoint_published{edge="%s",pool="%s",endpoint="%s",reason="%s"} %d', $endpoint->edge_id, $endpoint->edge_pool_id, $endpoint->id, $endpoint->dns_reason ?? 'unknown', $endpoint->dns_published ? 1 : 0);
            $lines[] = sprintf('cdnfoundry_pool_endpoint_revision_mismatch{edge="%s",pool="%s",endpoint="%s"} %d', $endpoint->edge_id, $endpoint->edge_pool_id, $endpoint->id, $endpoint->gateway_revision < $endpoint->revision ? 1 : 0);
        });
        EdgePool::query()->where('enabled', true)->orderBy('id')->limit(1000)->get(['id', 'dns_fail_static'])->each(function (EdgePool $pool) use (&$lines): void {
            $lines[] = sprintf('cdnfoundry_pool_dns_fail_static{pool="%d"} %d', $pool->id, $pool->dns_fail_static ? 1 : 0);
        });
        Edge::query()->where('enabled', true)->whereNotNull('registered_at')->orderBy('id')->limit(1000)->get(['id', 'name', 'last_heartbeat_at', 'identity_certificate_expires_at', 'reported_identity_expires_at'])->each(function (Edge $edge) use (&$lines): void {
            $lines[] = sprintf('cdnfoundry_edge_heartbeat_age_seconds{edge="%s",pop="%s"} %d', $edge->id, $this->label($edge->name), $edge->last_heartbeat_at === null ? -1 : (int) $edge->last_heartbeat_at->diffInSeconds(now()));
            $expires = $edge->reported_identity_expires_at ?? $edge->identity_certificate_expires_at;
            if ($expires !== null) {
                $lines[] = sprintf('cdnfoundry_edge_identity_expires_seconds{edge="%s"} %d', $edge->id, $expires->timestamp - now()->timestamp);
            }
        });
        $lines[] = '# HELP cdnfoundry_emergency_modes_active Emergency modes switched on and not yet expired.';
        $lines[] = '# TYPE cdnfoundry_emergency_modes_active gauge';
        $lines[] = 'cdnfoundry_emergency_modes_active '.EmergencyMode::query()->where('active', true)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        $lines[] = '# HELP cdnfoundry_admin_logins_new_network_recent Administrator sign-ins in the last 15 minutes from a network that administrator had not used in the previous 30 days.';
        $lines[] = '# TYPE cdnfoundry_admin_logins_new_network_recent gauge';
        $lines[] = 'cdnfoundry_admin_logins_new_network_recent '.$this->newNetworkAdminLogins();
        $lines[] = 'cdnfoundry_fleet_rollouts_paused '.FleetRollout::query()->where('status', 'paused')->count();
        $lines[] = 'cdnfoundry_tls_certificates_expiring '.TlsCertificate::query()->where('status', 'active')->where('expires_at', '<=', now()->addDays((int) config('services.acme.expiry_alert_days')))->count();

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * How long changes take to reach the fleet: purge requests to applied, published edge artifacts to
     * applied, DNS deployments to deployed, and certificate issuance. Each value is derived from rows that
     * clear themselves once the change lands, so none of them is a lifetime counter.
     *
     * @return list<string>
     */
    private function propagationLines(): array
    {
        $age = fn (mixed $timestamp): int => $timestamp === null ? 0 : max(0, (int) CarbonImmutable::parse($timestamp)->diffInSeconds(now(), true));
        $span = fn (mixed $from, mixed $to): int => abs(CarbonImmutable::parse($to)->getTimestamp() - CarbonImmutable::parse($from)->getTimestamp());
        $hour = now()->subHour();
        $lines = [];

        $pendingPurge = CachePurge::query()->whereIn('status', ['pending', 'running'])->min('created_at');
        $settled = CachePurge::query()->where('status', 'succeeded')->where('updated_at', '>=', $hour)->orderByDesc('updated_at')->limit(500)->get(['created_at', 'updated_at']);
        $lines[] = '# HELP cdnfoundry_purge_pending_oldest_seconds Age of the oldest purge not yet applied on every PoP.';
        $lines[] = '# TYPE cdnfoundry_purge_pending_oldest_seconds gauge';
        $lines[] = 'cdnfoundry_purge_pending_oldest_seconds '.$age($pendingPurge);
        $lines[] = '# HELP cdnfoundry_purge_propagation_seconds_max Longest request-to-applied time of purges finished in the last hour.';
        $lines[] = '# TYPE cdnfoundry_purge_propagation_seconds_max gauge';
        $lines[] = 'cdnfoundry_purge_propagation_seconds_max '.(int) $settled->map(fn (CachePurge $purge): int => $span($purge->created_at, $purge->updated_at))->max();

        $unapplied = EdgeArtifact::query()->join('edges', 'edges.id', '=', 'edge_artifacts.edge_id')
            ->where('edges.enabled', true)->whereColumn('edge_artifacts.sequence', '>', 'edges.active_sequence');
        $lines[] = '# HELP cdnfoundry_config_pending_oldest_seconds Age of the oldest published edge artifact an enabled PoP has not applied.';
        $lines[] = '# TYPE cdnfoundry_config_pending_oldest_seconds gauge';
        $lines[] = 'cdnfoundry_config_pending_oldest_seconds '.$age((clone $unapplied)->min('edge_artifacts.created_at'));

        $lines[] = '# HELP cdnfoundry_dns_serial_pending_oldest_seconds Age of the oldest DNS deployment that has not reached its desired revision.';
        $lines[] = '# TYPE cdnfoundry_dns_serial_pending_oldest_seconds gauge';
        $lines[] = 'cdnfoundry_dns_serial_pending_oldest_seconds '.$age(DnsDeployment::query()->whereIn('status', ['pending', 'deploying', 'failed'])->whereColumn('desired_revision', '>', 'deployed_revision')->min('updated_at'));

        $day = now()->subDay();
        $lines[] = '# HELP cdnfoundry_acme_orders_day Managed certificate orders that finished in the last 24 hours, by result.';
        $lines[] = '# TYPE cdnfoundry_acme_orders_day gauge';
        foreach (['succeeded', 'failed'] as $status) {
            $lines[] = sprintf('cdnfoundry_acme_orders_day{status="%s"} %d', $status, TlsOrder::query()->where('status', $status)->where('finished_at', '>=', $day)->count());
        }
        $issued = TlsOrder::query()->where('status', 'succeeded')->where('finished_at', '>=', $day)->whereNotNull('started_at')->orderByDesc('finished_at')->limit(500)->get(['started_at', 'finished_at']);
        $lines[] = '# HELP cdnfoundry_acme_issuance_seconds_max Longest issuance of a managed certificate finished in the last 24 hours.';
        $lines[] = '# TYPE cdnfoundry_acme_issuance_seconds_max gauge';
        $lines[] = 'cdnfoundry_acme_issuance_seconds_max '.(int) $issued->map(fn (TlsOrder $order): int => $span($order->started_at, $order->finished_at))->max();

        return $lines;
    }

    private function newNetworkAdminLogins(): int
    {
        $recent = AuditLog::query()->where('action', 'auth.login')->where('created_at', '>=', now()->subMinutes(15))
            ->whereHas('actor', fn ($query) => $query->where('type', 'admin'))->orderBy('id')->limit(200)->get(['id', 'actor_id', 'ip_address', 'created_at']);
        $count = 0;
        foreach ($recent as $login) {
            $network = ClientNetwork::key($login->ip_address);
            if ($network === null) {
                continue;
            }
            $seen = AuditLog::query()->where('action', 'auth.login')->where('actor_id', $login->actor_id)->where('id', '<', $login->id)
                ->where('created_at', '>=', CarbonImmutable::parse($login->created_at)->subDays(30))->whereNotNull('ip_address')->distinct()->limit(500)->pluck('ip_address')
                ->contains(fn (string $address): bool => ClientNetwork::key($address) === $network);
            $count += $seen ? 0 : 1;
        }

        return $count;
    }

    /** A value safe inside a Prometheus label: one line, with quotes and backslashes escaped. */
    private function label(string $value): string
    {
        return addcslashes(str_replace(["\n", "\r"], ' ', $value), '"\\');
    }
}
