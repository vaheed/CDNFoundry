<?php

namespace App\Support;

use App\Models\Backup;
use App\Models\CachePurge;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\EdgeTask;
use App\Models\EmergencyMode;
use App\Models\Operation;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Models\UsageRollup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

final class SystemHealth
{
    public const QUEUES = ['interactive', 'runtime', 'certificate_purge', 'bulk_maintenance'];

    public function components(): array
    {
        $components = [];
        $components['control_database'] = $this->probe(fn () => DB::select('select 1'));
        $components['queue_backend'] = $this->probe(fn () => Redis::connection()->command('ping'));
        $components['queue_workers'] = $this->horizon();
        $heartbeat = Cache::get('operations:scheduler_heartbeat');
        $schedulerStale = $heartbeat === null || now()->diffInSeconds($heartbeat) > app(PlatformSettings::class)->integer('operations', 'scheduler_stale_seconds');
        $components['scheduler'] = $this->state($schedulerStale ? 'degraded' : 'healthy', ['last_heartbeat_at' => $heartbeat]);
        $components['clickhouse'] = $this->probe(fn () => Http::connectTimeout(1)->timeout(2)->get(config('services.clickhouse.url').'/ping')->throw());
        $components['vector'] = $this->probe(fn () => Http::connectTimeout(1)->timeout(2)->get(config('services.vector.metrics_url'))->throw());
        $components['host_clock'] = $this->clock();
        $components['mmdb'] = $this->mmdb();

        $heartbeatSeconds = app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds');
        $enabledEdges = Edge::query()->where('enabled', true)->count();
        $staleEdges = Edge::query()->where('enabled', true)->where(fn ($query) => $query->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subSeconds($heartbeatSeconds)))->count();
        $components['edges'] = $this->state($enabledEdges === 0 ? 'degraded' : ($staleEdges > 0 ? 'degraded' : 'healthy'), ['enabled' => $enabledEdges, 'stale' => $staleEdges]);
        $listenerFailures = Edge::query()->where('enabled', true)->where('drained', false)
            ->where(fn ($query) => $query->whereNull('capacity->listener_ready')->orWhere('capacity->listener_ready', '!=', true))->count();
        $components['edge_listeners'] = $this->state($listenerFailures > 0 ? 'degraded' : 'healthy', ['not_ready' => $listenerFailures]);

        $enabledCells = EdgeCell::query()->whereHas('edge', fn ($query) => $query->where('enabled', true))->count();
        $unhealthyCells = EdgeCell::query()->whereHas('edge', fn ($query) => $query->where('enabled', true))
            ->whereNotIn('status', ['ready', 'drained'])->count();
        $components['edge_cells'] = $this->state($enabledCells === 0 || $unhealthyCells > 0 ? 'degraded' : 'healthy', ['assigned' => $enabledCells, 'unhealthy' => $unhealthyCells]);

        $enabledPools = EdgePool::query()->where('enabled', true)->where('withdrawn', false)->count();
        $unavailablePools = EdgePool::query()->where('enabled', true)->where('withdrawn', false)
            ->whereDoesntHave('cells', fn ($query) => $query->where('drained', false)->where('status', 'ready')
                ->whereHas('edge', fn ($edge) => $edge->readyForTraffic()))->count();
        $components['service_pools'] = $this->state($enabledPools === 0 || $unavailablePools > 0 ? 'degraded' : 'healthy', ['enabled' => $enabledPools, 'unavailable' => $unavailablePools]);

        $latestArtifacts = DB::table('edge_artifacts')->selectRaw('edge_id, max(sequence) as latest_sequence')->groupBy('edge_id');
        $configurationDrift = Edge::query()->where('edges.enabled', true)->joinSub($latestArtifacts, 'latest_edge_artifacts', fn ($join) => $join->on('edges.id', '=', 'latest_edge_artifacts.edge_id'))
            ->whereColumn('edges.active_sequence', '<', 'latest_edge_artifacts.latest_sequence')->count();
        $deploymentRejections = Edge::query()->where('enabled', true)->whereNotNull('capacity->last_rejection')->count();
        $components['edge_configuration'] = $this->state(($configurationDrift + $deploymentRejections) > 0 ? 'degraded' : 'healthy', ['stale_edges' => $configurationDrift, 'rejected_candidates' => $deploymentRejections]);

        $failedPlacements = DomainEdgePlacement::query()->where('state', 'failed')->count();
        $placementDrift = DomainEdgePlacement::query()->where('state', 'active')->join('domains', 'domains.id', '=', 'domain_edge_placements.domain_id')
            ->where(fn ($query) => $query->whereNull('domains.active_edge_revision')->orWhereColumn('domains.active_edge_revision', '<', 'domain_edge_placements.desired_revision'))->count();
        $components['edge_placements'] = $this->state(($failedPlacements + $placementDrift) > 0 ? 'degraded' : 'healthy', ['failed' => $failedPlacements, 'drifted' => $placementDrift]);
        $components['edge_capacity'] = $this->edgeCapacity();

        $activeEmergencyModes = EmergencyMode::query()->where('active', true)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        $withdrawnPools = EdgePool::query()->where('enabled', true)->where('withdrawn', true)->count();
        $components['emergency_modes'] = $this->state(($activeEmergencyModes + $withdrawnPools) > 0 ? 'degraded' : 'healthy', ['active' => $activeEmergencyModes, 'withdrawn_pools' => $withdrawnPools]);

        $enabledClusters = DnsCluster::query()->where('enabled', true)->count();
        $badClusters = DnsCluster::query()->where('enabled', true)->where(fn ($query) => $query->where('last_health_status', '!=', 'healthy')->orWhereNull('last_health_at'))->count();
        $dnsDrift = DnsDeployment::query()->whereIn('status', ['failed', 'pending'])->count();
        $components['authoritative_dns'] = $this->state($enabledClusters === 0 ? 'degraded' : (($badClusters + $dnsDrift) > 0 ? 'degraded' : 'healthy'), ['enabled_clusters' => $enabledClusters, 'unhealthy_clusters' => $badClusters, 'drifted_deployments' => $dnsDrift]);
        $components['dns_deployments'] = $this->state($dnsDrift > 0 ? 'degraded' : 'healthy', ['drifted' => $dnsDrift]);

        $expiring = TlsCertificate::query()->where('status', 'active')->where('expires_at', '<=', now()->addDays((int) config('services.acme.expiry_alert_days')))->count();
        $failedOrders = TlsOrder::query()->where('status', 'failed')->count();
        $components['tls'] = $this->state(($expiring + $failedOrders) > 0 ? 'degraded' : 'healthy', ['expiring_certificates' => $expiring, 'failed_orders' => $failedOrders]);

        $failedPurges = CachePurge::query()->where('status', 'failed')->count();
        $failedTasks = EdgeTask::query()->where('status', 'failed')->count();
        $components['runtime_tasks'] = $this->state(($failedPurges + $failedTasks) > 0 ? 'degraded' : 'healthy', ['failed_purges' => $failedPurges, 'failed_edge_tasks' => $failedTasks]);
        $pendingPurges = CachePurge::query()->whereIn('status', ['pending', 'running'])->count();
        $components['purges'] = $this->state($failedPurges > 0 ? 'degraded' : 'healthy', ['failed' => $failedPurges, 'pending' => $pendingPurges]);

        $usageLag = UsageRollup::query()->where('status', 'finalized')->max('interval_end');
        $components['usage'] = $this->state($usageLag === null || now()->diffInHours($usageLag) > 3 ? 'degraded' : 'healthy', ['last_finalized_interval' => $usageLag]);
        $components['operations'] = $this->state(Operation::query()->where('status', 'failed')->exists() ? 'degraded' : 'healthy', ['failed' => Operation::query()->where('status', 'failed')->count()]);
        $lastBackup = Backup::query()->where('status', 'succeeded')->whereNotNull('verified_at')->max('verified_at');
        $backupStale = $lastBackup === null || now()->diffInHours($lastBackup) > app(PlatformSettings::class)->integer('operations', 'backup_stale_hours');
        $components['backups'] = $this->state($backupStale ? 'degraded' : 'healthy', ['last_verified_at' => $lastBackup]);

        return $components;
    }

    public function queues(): array
    {
        return collect(self::QUEUES)->mapWithKeys(function (string $queue): array {
            try {
                $depth = (int) Redis::connection()->llen("queues:{$queue}");
                $payload = $depth > 0 ? json_decode((string) Redis::connection()->lindex("queues:{$queue}", 0), true) : null;
                $pushedAt = is_array($payload) ? ($payload['pushedAt'] ?? $payload['pushed_at'] ?? null) : null;
                $oldestAge = is_numeric($pushedAt) ? max(0, (int) floor(microtime(true) - $pushedAt)) : null;

                return [$queue => ['status' => $depth > 1000 || ($oldestAge !== null && $oldestAge > 900) ? 'degraded' : 'healthy', 'depth' => $depth, 'oldest_job_age_seconds' => $oldestAge]];
            } catch (Throwable) {
                return [$queue => ['status' => 'unavailable', 'depth' => null, 'oldest_job_age_seconds' => null]];
            }
        })->all();
    }

    public function overall(array $components): string
    {
        if (collect(['control_database', 'queue_backend'])->contains(fn (string $name) => ($components[$name]['status'] ?? 'unavailable') === 'unavailable')) {
            return 'unavailable';
        }

        return collect($components)->contains(fn (array $component) => $component['status'] !== 'healthy') ? 'degraded' : 'healthy';
    }

    private function probe(callable $probe): array
    {
        $started = hrtime(true);
        try {
            $probe();

            return $this->state('healthy', ['latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2)]);
        } catch (Throwable $exception) {
            return $this->state('unavailable', ['latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2), 'error_code' => class_basename($exception)]);
        }
    }

    private function clock(): array
    {
        try {
            $response = Http::connectTimeout(1)->timeout(2)->get(config('services.prometheus.url').'/api/v1/query', ['query' => 'node_timex_offset_seconds'])->throw()->json();
            $rows = data_get($response, 'data.result', []);
            if (! is_array($rows) || $rows === []) {
                return $this->state('degraded', ['error_code' => 'clock_metric_missing']);
            }
            $offset = collect($rows)->map(fn (array $row): float => abs((float) data_get($row, 'value.1', 0)))->max();
            $limit = app(PlatformSettings::class)->integer('operations', 'clock_drift_warning_seconds');

            return $this->state($offset > $limit ? 'degraded' : 'healthy', ['maximum_offset_seconds' => $offset, 'warning_seconds' => $limit, 'sources' => count($rows)]);
        } catch (Throwable $exception) {
            return $this->state('unavailable', ['error_code' => class_basename($exception)]);
        }
    }

    private function horizon(): array
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();
            $running = collect($masters)->where('status', 'running')->count();

            return $this->state($running > 0 && $running === count($masters) ? 'healthy' : 'degraded', [
                'running_masters' => $running,
                'known_masters' => count($masters),
            ]);
        } catch (Throwable $exception) {
            return $this->state('unavailable', ['error_code' => class_basename($exception)]);
        }
    }

    private function mmdb(): array
    {
        $path = (string) config('services.geoip.database');
        $maximumAgeHours = (int) config('services.geoip.stale_hours', 48);
        clearstatcache(true, $path);
        if (! is_file($path) || ! is_readable($path) || filesize($path) === 0) {
            return $this->state('unavailable', ['error_code' => 'mmdb_missing']);
        }
        $modified = filemtime($path);
        if ($modified === false) {
            return $this->state('unavailable', ['error_code' => 'mmdb_stat_failed']);
        }
        $ageHours = max(0, (now()->timestamp - $modified) / 3600);

        return $this->state($ageHours > $maximumAgeHours ? 'degraded' : 'healthy', [
            'updated_at' => date(DATE_ATOM, $modified),
            'age_hours' => round($ageHours, 2),
            'stale_after_hours' => $maximumAgeHours,
        ]);
    }

    private function edgeCapacity(): array
    {
        $cells = EdgeCell::query()->whereHas('edge', fn ($query) => $query->where('enabled', true))->limit(1001)->get(['capacity']);
        $truncated = $cells->count() > 1000;
        $pressured = $cells->take(1000)->filter(function (EdgeCell $cell): bool {
            $capacity = $cell->capacity ?? [];
            foreach ([
                ['memory_usage', 'memory_limit'],
                ['cache_usage', 'cache_limit'],
                ['temporary_storage_usage', 'temporary_storage_limit'],
                ['active_connections', 'connection_limit'],
            ] as [$usedKey, $limitKey]) {
                $used = data_get($capacity, $usedKey);
                $limit = data_get($capacity, $limitKey);
                if (is_numeric($used) && is_numeric($limit) && (float) $limit > 0 && ((float) $used / (float) $limit) >= 0.8) {
                    return true;
                }
            }

            return false;
        })->count();

        return $this->state($truncated || $pressured > 0 ? 'degraded' : 'healthy', [
            'pressured_cells' => $pressured,
            'scanned_cells' => min(1000, $cells->count()),
            'scan_truncated' => $truncated,
        ]);
    }

    private function state(string $status, array $details): array
    {
        return ['status' => $status, 'checked_at' => now()->toIso8601String(), 'details' => $details];
    }
}
