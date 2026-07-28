<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\DnsClusters\DnsClusterResource;
use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Filament\Admin\Resources\Operations\OperationResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\Operation;
use App\Models\User;
use App\Support\SystemHealth;
use Filament\Pages\Dashboard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Throwable;

class AdminDashboard extends Dashboard
{
    protected string $view = 'filament.admin.pages.dashboard';

    public function getSubheading(): ?string
    {
        return 'Control-plane health and recent operator activity.';
    }

    public function getSummaryProperty(): array
    {
        $domainState = Domain::query()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN lifecycle_state = 'active' THEN 1 ELSE 0 END) AS active")->firstOrFail();
        $userState = User::query()->selectRaw('COUNT(*) AS total, SUM(CASE WHEN disabled_at IS NULL THEN 1 ELSE 0 END) AS active')->firstOrFail();
        $clusterState = DnsCluster::query()->selectRaw("SUM(CASE WHEN enabled = true THEN 1 ELSE 0 END) AS enabled_count, SUM(CASE WHEN enabled = true AND last_health_status = 'healthy' THEN 1 ELSE 0 END) AS healthy_count")->firstOrFail();
        $edgeState = Edge::query()->selectRaw('SUM(CASE WHEN enabled = true THEN 1 ELSE 0 END) AS enabled_count, SUM(CASE WHEN enabled = true AND drained = false AND last_heartbeat_at IS NOT NULL THEN 1 ELSE 0 END) AS ready_count')->firstOrFail();
        $operationState = Operation::query()->selectRaw("SUM(CASE WHEN status IN ('pending', 'running') THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")->firstOrFail();
        $domains = (int) $domainState->total;
        $activeDomains = (int) $domainState->active;
        $users = (int) $userState->total;
        $activeUsers = (int) $userState->active;
        $clusters = (int) $clusterState->enabled_count;
        $healthyClusters = (int) $clusterState->healthy_count;
        $edges = (int) $edgeState->enabled_count;
        $readyEdges = (int) $edgeState->ready_count;
        $pendingOperations = (int) $operationState->pending;
        $failedOperations = (int) $operationState->failed;

        return [
            ['label' => 'Domains', 'value' => $domains, 'description' => "{$activeDomains} active", 'tone' => $domains === 0 ? 'neutral' : 'primary', 'url' => DomainResource::getUrl()],
            ['label' => 'Users', 'value' => $users, 'description' => "{$activeUsers} active accounts", 'tone' => 'neutral', 'url' => UserResource::getUrl()],
            ['label' => 'DNS clusters', 'value' => $clusters, 'description' => "{$healthyClusters} healthy and enabled", 'tone' => $clusters > 0 && $healthyClusters === $clusters ? 'success' : 'warning', 'url' => DnsClusterResource::getUrl()],
            ['label' => 'Serving edges', 'value' => $readyEdges, 'description' => "{$edges} enabled", 'tone' => $edges > 0 && $readyEdges === $edges ? 'success' : 'warning', 'url' => EdgeResource::getUrl()],
            ['label' => 'Work in progress', 'value' => $pendingOperations, 'description' => 'Pending or running operations', 'tone' => $pendingOperations > 0 ? 'warning' : 'success', 'url' => OperationResource::getUrl()],
            ['label' => 'Failed operations', 'value' => $failedOperations, 'description' => $failedOperations > 0 ? 'Operator review required' : 'No unresolved failures', 'tone' => $failedOperations > 0 ? 'danger' : 'success', 'url' => OperationResource::getUrl()],
        ];
    }

    public function getRecentAuditsProperty(): Collection
    {
        return AuditLog::query()->with('actor')->latest('id')->limit(8)->get();
    }

    public function getQueueStateProperty(): array
    {
        $lanes = [
            'interactive' => 'Interactive',
            'runtime' => 'Runtime deployment',
            'certificate_purge' => 'Certificates and purge',
            'bulk_maintenance' => 'Bulk maintenance',
        ];

        try {
            return collect($lanes)->map(function (string $label, string $queue): array {
                $depth = (int) Redis::connection()->llen("queues:$queue");
                $payload = $depth > 0 ? Redis::connection()->lindex("queues:$queue", 0) : null;
                $pushedAt = is_string($payload) ? (json_decode($payload, true)['pushedAt'] ?? null) : null;

                return [
                    'key' => $queue,
                    'label' => $label,
                    'depth' => $depth,
                    'oldest' => $this->queueAge(is_numeric($pushedAt) ? (int) $pushedAt : null, $depth),
                    'tone' => $depth > 0 ? 'warning' : 'success',
                ];
            })->values()->all();
        } catch (Throwable) {
            return [['key' => 'unavailable', 'label' => 'Queue backend', 'depth' => null, 'oldest' => 'Age unavailable', 'tone' => 'danger']];
        }
    }

    public function getQuickLinksProperty(): array
    {
        return [
            ['label' => 'Platform settings', 'icon' => 'heroicon-o-adjustments-horizontal', 'url' => PlatformSettings::getUrl()],
            ['label' => 'System DNS identity', 'icon' => 'heroicon-o-globe-alt', 'url' => SystemDnsIdentity::getUrl()],
            ['label' => 'Operations', 'icon' => 'heroicon-o-arrow-path', 'url' => OperationResource::getUrl()],
        ];
    }

    public function getComponentStateProperty(): array
    {
        try {
            return collect(app(SystemHealth::class)->components())->map(fn (array $state, string $name): array => [
                'key' => $name,
                'name' => str($name)->replace('_', ' ')->headline()->toString(),
                ...$state,
                'summary' => $this->healthDetails($state['details'] ?? []),
                'guidance' => $this->healthGuidance($name),
            ])->values()->all();
        } catch (Throwable) {
            return [[
                'key' => 'operational_health', 'name' => 'Operational health', 'status' => 'unavailable',
                'checked_at' => now()->toIso8601String(), 'details' => [], 'summary' => 'Health checks could not be loaded.',
                'guidance' => 'Check the control application logs and database connectivity.',
            ]];
        }
    }

    private function healthDetails(array $details): string
    {
        if ($details === []) {
            return 'No additional details reported.';
        }

        return collect($details)->map(function (mixed $value, string $key): string {
            $label = str($key)->replace('_', ' ')->headline();
            $formatted = match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                $value === null => 'not available',
                is_float($value) => number_format($value, 2),
                is_int($value) => number_format($value),
                default => (string) $value,
            };

            return "{$label}: {$formatted}";
        })->implode(' · ');
    }

    private function healthGuidance(string $component): string
    {
        return match ($component) {
            'control_database' => 'Check PostgreSQL health, credentials, and the private control network.',
            'queue_backend' => 'Check Valkey health and control-plane connectivity.',
            'queue_workers' => 'Start or restart Horizon and confirm every bounded queue lane has a running supervisor.',
            'scheduler' => 'Confirm the scheduler container is running every minute and can write its cache heartbeat.',
            'clickhouse' => 'Check ClickHouse health and follow the telemetry outage runbook.',
            'vector' => 'Check Vector health, sources, disk buffers, and ClickHouse delivery.',
            'host_clock' => 'Check Prometheus node time metrics and synchronize hosts with NTP.',
            'mmdb' => 'Run the MMDB updater and verify the database file is readable and current.',
            'edges', 'edge_listeners', 'edge_cells', 'service_pools' => 'Open Edge network, inspect the named edge/pool, then verify heartbeat, gateway, and assigned cell readiness.',
            'edge_configuration', 'edge_placements' => 'Inspect failed operations and deployment rejections, then reconcile only the affected domain or edge.',
            'edge_capacity' => 'Add capacity or drain/move workloads from cells at or above 80% of a reported limit.',
            'emergency_modes' => 'Review active emergency controls and intentionally withdrawn pools; remove them only after the incident is resolved.',
            'authoritative_dns', 'dns_deployments' => 'Check DNS cluster health and failed/pending deployments while preserving the previous valid zone.',
            'tls' => 'Review failed orders and certificates inside the expiry alert window.',
            'runtime_tasks', 'purges' => 'Review failed edge tasks or purges and retry after correcting the bounded failure reason.',
            'usage' => 'Check the scheduler, usage finalizer, bulk-maintenance queue, and ClickHouse availability.',
            'operations' => 'Open Operations, inspect failed rows, and retry only after correcting their stable failure reason.',
            'backups' => 'Run and verify a current backup, including keys and externally stored TLS material.',
            default => 'Inspect the component details and the corresponding operations runbook.',
        };
    }

    private function queueAge(?int $pushedAt, int $depth): string
    {
        if ($depth === 0) {
            return 'No queued jobs';
        }

        if ($pushedAt === null) {
            return 'Oldest age unavailable';
        }

        $seconds = max(0, now()->timestamp - $pushedAt);

        return match (true) {
            $seconds < 60 => "Oldest {$seconds}s",
            $seconds < 3600 => 'Oldest '.intdiv($seconds, 60).'m',
            default => 'Oldest '.intdiv($seconds, 3600).'h',
        };
    }
}
