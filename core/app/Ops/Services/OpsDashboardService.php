<?php

namespace App\Ops\Services;

use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\Operation;
use App\Ops\Data\OpsDashboardContext;
use App\Support\AnalyticsStore;
use App\Support\PlatformSettings;
use App\Support\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OpsDashboardService
{
    public function __construct(
        private readonly AnalyticsStore $analytics,
        private readonly SystemHealth $health,
    ) {}

    /** @return array<string, mixed> */
    public function traffic(OpsDashboardContext $context): array
    {
        if (! $context->isValid()) {
            return $this->invalid($context);
        }

        return $this->remember('traffic', $context, 15, function () use ($context): array {
            try {
                $domain = $context->domainId === null ? null : Domain::query()->find($context->domainId);
                $current = $this->normalizeTrafficRows($this->analytics->operationalTrafficSeries($domain, $context->rangeValue(), $context->edgeId));
                $previous = $context->compare
                    ? $this->normalizeTrafficRows($this->analytics->operationalTrafficSeries($domain, $context->comparisonRange(), $context->edgeId))
                    : [];

                return [
                    'available' => true,
                    'state' => $current === [] ? 'no_data' : $this->freshnessState($this->latestBucket($current)),
                    'current' => $current,
                    'previous' => $previous,
                    'summary' => $this->trafficSummary($current),
                    'previous_summary' => $context->compare ? $this->trafficSummary($previous) : null,
                    'source_timestamp' => $this->latestBucket($current),
                    'queried_at' => now('UTC')->toIso8601String(),
                    'resolution' => '1 hour',
                    'partial' => $this->rangeIsPartial($context),
                ];
            } catch (Throwable $exception) {
                $this->reportFailure('traffic', $exception);

                return $this->unavailable('Traffic aggregates could not be queried.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function dns(OpsDashboardContext $context): array
    {
        if (! $context->isValid()) {
            return $this->invalid($context);
        }

        return $this->remember('dns', $context, 30, function () use ($context): array {
            try {
                $domain = $context->domainId === null ? null : Domain::query()->find($context->domainId);
                $current = $this->normalizeDnsRows($this->analytics->operationalDnsSeries($domain, $context->rangeValue()));
                $previous = $context->compare
                    ? $this->normalizeDnsRows($this->analytics->operationalDnsSeries($domain, $context->comparisonRange()))
                    : [];
                $clusters = DnsCluster::query()->where('enabled', true)
                    ->selectRaw("COUNT(*) AS enabled, SUM(CASE WHEN last_health_status = 'healthy' THEN 1 ELSE 0 END) AS healthy")
                    ->first();

                return [
                    'available' => true,
                    'state' => $current === [] ? 'no_data' : $this->freshnessState($this->latestBucket($current)),
                    'current' => $current,
                    'previous' => $previous,
                    'summary' => $this->dnsSummary($current),
                    'previous_summary' => $context->compare ? $this->dnsSummary($previous) : null,
                    'source_timestamp' => $this->latestBucket($current),
                    'queried_at' => now('UTC')->toIso8601String(),
                    'clusters' => ['enabled' => (int) ($clusters?->enabled ?? 0), 'healthy' => (int) ($clusters?->healthy ?? 0)],
                    'latency_available' => false,
                    'partial' => $this->rangeIsPartial($context),
                ];
            } catch (Throwable $exception) {
                $this->reportFailure('dns', $exception);

                return $this->unavailable('DNS aggregates could not be queried.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function system(OpsDashboardContext $context): array
    {
        if (! $context->isValid()) {
            return $this->invalid($context);
        }

        return $this->remember('system', $context, 10, function () use ($context): array {
            try {
                $components = $this->health->components();
                $conditions = $this->conditions($components);
                $unhealthy = collect($components)->filter(fn (array $component): bool => ($component['status'] ?? 'unavailable') !== 'healthy');
                $servingCritical = $unhealthy->contains(fn (array $component, string $name): bool => in_array($name, ['authoritative_dns', 'edges', 'edge_listeners', 'edge_cells', 'service_pools'], true)
                    && ($component['status'] ?? null) === 'unavailable'
                );
                $status = match (true) {
                    $components === [] => 'unknown',
                    $servingCritical => 'critical',
                    $unhealthy->isNotEmpty() && $unhealthy->keys()->every(fn (string $name): bool => $name === 'emergency_modes') => 'maintenance',
                    $unhealthy->isNotEmpty() => 'degraded',
                    default => 'healthy',
                };
                $heartbeatSeconds = app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds');
                $staleEdgesQuery = Edge::query()->where('enabled', true)
                    ->where(fn ($query) => $query->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subSeconds($heartbeatSeconds)));
                $failedOperations = Operation::query()->where('status', 'failed')
                    ->where('created_at', '>=', $context->from)
                    ->where('created_at', '<', $context->to);

                return [
                    'available' => true,
                    'state' => $status,
                    'components' => $components,
                    'conditions' => $conditions,
                    'active_condition_count' => count($conditions),
                    'affected_domains' => (clone $failedOperations)->whereNotNull('input->domain_id')->distinct()->count('input->domain_id'),
                    'affected_edges' => (clone $staleEdgesQuery)->count(),
                    'affected_regions' => (clone $staleEdgesQuery)->whereNotNull('continent_code')->distinct()->count('continent_code'),
                    'started_at' => collect([
                        (clone $failedOperations)->min('created_at'),
                        (clone $staleEdgesQuery)->min('last_heartbeat_at'),
                    ])->filter()->sort()->first(),
                    'checked_at' => now('UTC')->toIso8601String(),
                ];
            } catch (Throwable $exception) {
                $this->reportFailure('system', $exception);

                return $this->unavailable('Operational health could not be evaluated.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function queues(OpsDashboardContext $context): array
    {
        if (! $context->isValid()) {
            return $this->invalid($context);
        }

        return $this->remember('queues', $context, 10, function (): array {
            $labels = [
                'interactive' => 'Interactive',
                'runtime' => 'Runtime deployment',
                'certificate_purge' => 'Certificates and purge',
                'bulk_maintenance' => 'Bulk maintenance',
            ];

            try {
                $items = collect($this->health->queues())->map(function (array $state, string $queue) use ($labels): array {
                    return [
                        'queue' => $queue,
                        'label' => $labels[$queue] ?? str($queue)->replace('_', ' ')->headline()->toString(),
                        ...$state,
                    ];
                })->values()->all();

                return ['available' => true, 'state' => collect($items)->contains(fn (array $item): bool => $item['status'] !== 'healthy') ? 'degraded' : 'healthy', 'items' => $items, 'queried_at' => now('UTC')->toIso8601String()];
            } catch (Throwable $exception) {
                $this->reportFailure('queues', $exception);

                return $this->unavailable('Queue state could not be queried.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function operations(OpsDashboardContext $context): array
    {
        if (! $context->isValid()) {
            return $this->invalid($context);
        }

        return $this->remember('operations', $context, 10, function () use ($context): array {
            try {
                $operationQuery = Operation::query()->with('actor:id,email')->latest('created_at')->limit(12);
                if ($context->domainId !== null) {
                    $operationQuery->where('input->domain_id', $context->domainId);
                }
                if ($context->edgeId !== null) {
                    $operationQuery->where('input->edge_id', $context->edgeId);
                }
                $operations = $operationQuery->get()->map(fn (Operation $operation): array => [
                    'id' => $operation->id,
                    'kind' => 'operation',
                    'type' => $operation->type,
                    'target' => $this->operationTarget($operation),
                    'actor' => $operation->actor?->email ?? 'System',
                    'status' => $operation->status,
                    'occurred_at' => $operation->created_at?->toIso8601String(),
                    'duration_seconds' => $operation->started_at && $operation->finished_at
                        ? $operation->started_at->diffInSeconds($operation->finished_at)
                        : null,
                ]);
                $audits = collect();
                if ($context->domainId === null && $context->edgeId === null) {
                    $audits = AuditLog::query()->with('actor:id,email')->latest('id')->limit(12)->get()->map(fn (AuditLog $audit): array => [
                        'id' => (string) $audit->id,
                        'kind' => 'audit',
                        'type' => $audit->action,
                        'target' => $audit->subject_type ? class_basename($audit->subject_type).' '.($audit->subject_id ?? '') : 'Platform',
                        'actor' => $audit->actor?->email ?? 'System',
                        'status' => 'succeeded',
                        'occurred_at' => $audit->created_at?->toIso8601String(),
                        'duration_seconds' => null,
                    ]);
                }

                return [
                    'available' => true,
                    'state' => 'fresh',
                    'items' => $operations->concat($audits)->sortByDesc('occurred_at')->take(12)->values()->all(),
                    'queried_at' => now('UTC')->toIso8601String(),
                ];
            } catch (Throwable $exception) {
                $this->reportFailure('operations', $exception);

                return $this->unavailable('Recent operations could not be queried.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function freshness(OpsDashboardContext $context): array
    {
        $traffic = $this->traffic($context);
        $dns = $this->dns($context);
        $system = $this->system($context);
        $lastHeartbeat = Edge::query()->where('enabled', true)->max('last_heartbeat_at');
        $sources = [
            ['label' => 'HTTP aggregate bucket', 'timestamp' => $traffic['source_timestamp'] ?? null, 'state' => $traffic['state'] ?? 'unavailable'],
            ['label' => 'DNS aggregate bucket', 'timestamp' => $dns['source_timestamp'] ?? null, 'state' => $dns['state'] ?? 'unavailable'],
            ['label' => 'ClickHouse result', 'timestamp' => $traffic['queried_at'] ?? null, 'state' => ($traffic['available'] ?? false) ? 'fresh' : 'unavailable'],
            ['label' => 'Vector probe', 'timestamp' => data_get($system, 'components.vector.checked_at'), 'state' => $this->componentFreshness(data_get($system, 'components.vector.status'))],
            ['label' => 'Latest edge heartbeat', 'timestamp' => $lastHeartbeat, 'state' => $this->timestampFreshness($lastHeartbeat, 90, 300)],
            ['label' => 'Browser refresh', 'timestamp' => now('UTC')->toIso8601String(), 'state' => 'fresh'],
        ];
        $rank = ['fresh' => 0, 'delayed' => 1, 'stale' => 2, 'no_data' => 3, 'unavailable' => 4];
        $state = collect($sources)->sortByDesc(fn (array $source): int => $rank[$source['state']] ?? 4)->first()['state'] ?? 'unavailable';

        return ['available' => true, 'state' => $state, 'sources' => $sources, 'connection' => 'polling'];
    }

    /** @param array<string, array<string, mixed>> $components */
    private function conditions(array $components): array
    {
        return collect($components)
            ->filter(fn (array $component): bool => ($component['status'] ?? 'unavailable') !== 'healthy')
            ->map(function (array $component, string $name): array {
                $status = $component['status'] ?? 'unavailable';
                $critical = in_array($name, ['control_database', 'queue_backend', 'authoritative_dns', 'edges', 'edge_listeners', 'service_pools'], true);

                return [
                    'key' => $name,
                    'severity' => $status === 'unavailable' && $critical ? 'critical' : ($status === 'unavailable' ? 'high' : 'medium'),
                    'state' => 'open',
                    'summary' => str($name)->replace('_', ' ')->headline()->toString().' is '.str($status)->headline()->lower(),
                    'impact' => $this->conditionImpact($name, $component['details'] ?? []),
                    'started_at' => $component['checked_at'] ?? null,
                    'components' => [str($name)->replace('_', ' ')->headline()->toString()],
                ];
            })->sortBy(fn (array $condition): int => match ($condition['severity']) {
                'critical' => 0, 'high' => 1, 'medium' => 2, default => 3,
            })->take(8)->values()->all();
    }

    /** @param array<string, mixed> $details */
    private function conditionImpact(string $name, array $details): string
    {
        $values = collect($details)->filter(fn (mixed $value): bool => is_scalar($value) && $value !== '' && $value !== false)
            ->take(3)->map(fn (mixed $value, string $key): string => str($key)->replace('_', ' ')->headline().': '.(is_bool($value) ? 'yes' : (string) $value));

        return $values->isEmpty()
            ? match ($name) {
                'clickhouse', 'vector' => 'Observability is incomplete; serving remains independent.',
                default => 'Operator investigation is required.',
            }
        : $values->implode(' · ');
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function normalizeTrafficRows(array $rows): array
    {
        $numeric = ['requests', 'bytes_in', 'bytes_out', 'cache_hits', 'cache_misses', 'cache_bypass', 'cache_stale', 'cache_bytes_out', 'requests_4xx', 'requests_5xx', 'origin_errors', 'origin_latency_sum_ms', 'origin_latency_samples'];

        return array_map(function (array $row) use ($numeric): array {
            foreach ($numeric as $field) {
                $row[$field] = (float) ($row[$field] ?? 0);
            }

            return $row;
        }, $rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function normalizeDnsRows(array $rows): array
    {
        return array_map(function (array $row): array {
            foreach (['queries', 'successful', 'servfail', 'nxdomain', 'other'] as $field) {
                $row[$field] = (float) ($row[$field] ?? 0);
            }

            return $row;
        }, $rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function trafficSummary(array $rows): array
    {
        $fields = ['requests', 'bytes_in', 'bytes_out', 'cache_hits', 'cache_misses', 'cache_bypass', 'cache_stale', 'cache_bytes_out', 'requests_4xx', 'requests_5xx', 'origin_errors', 'origin_latency_sum_ms', 'origin_latency_samples'];
        $summary = [];
        foreach ($fields as $field) {
            $summary[$field] = array_sum(array_column($rows, $field));
        }
        $requests = $summary['requests'];
        $samples = $summary['origin_latency_samples'];
        $summary['cache_ratio'] = $requests > 0 ? $summary['cache_hits'] / $requests : null;
        $summary['rate_4xx'] = $requests > 0 ? $summary['requests_4xx'] / $requests : null;
        $summary['rate_5xx'] = $requests > 0 ? $summary['requests_5xx'] / $requests : null;
        $summary['origin_error_rate'] = $requests > 0 ? $summary['origin_errors'] / $requests : null;
        $summary['origin_average_latency_ms'] = $samples > 0 ? $summary['origin_latency_sum_ms'] / $samples : null;

        return $summary;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function dnsSummary(array $rows): array
    {
        $summary = [];
        foreach (['queries', 'successful', 'servfail', 'nxdomain', 'other'] as $field) {
            $summary[$field] = array_sum(array_column($rows, $field));
        }
        $summary['success_ratio'] = $summary['queries'] > 0 ? $summary['successful'] / $summary['queries'] : null;
        $summary['servfail_ratio'] = $summary['queries'] > 0 ? $summary['servfail'] / $summary['queries'] : null;

        return $summary;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function latestBucket(array $rows): ?string
    {
        $value = collect($rows)->pluck('bucket')->filter()->sort()->last();

        return is_string($value) ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
    }

    private function freshnessState(?string $timestamp): string
    {
        return $this->timestampFreshness($timestamp, 7200, 14400);
    }

    private function timestampFreshness(mixed $timestamp, int $delayedAfter, int $staleAfter): string
    {
        if (! is_string($timestamp) && ! $timestamp instanceof \DateTimeInterface) {
            return 'unavailable';
        }
        $age = abs(CarbonImmutable::now('UTC')->diffInSeconds(CarbonImmutable::parse($timestamp)));

        return match (true) {
            $age <= $delayedAfter => 'fresh',
            $age <= $staleAfter => 'delayed',
            default => 'stale',
        };
    }

    private function componentFreshness(mixed $status): string
    {
        return match ($status) {
            'healthy' => 'fresh',
            'degraded' => 'delayed',
            default => 'unavailable',
        };
    }

    private function rangeIsPartial(OpsDashboardContext $context): bool
    {
        $delay = app(PlatformSettings::class)->integer('telemetry', 'finalization_delay_minutes');

        return $context->to->isAfter(CarbonImmutable::now('UTC')->subMinutes($delay));
    }

    private function operationTarget(Operation $operation): string
    {
        return match (true) {
            filled(data_get($operation->input, 'domain_id')) => 'Domain #'.data_get($operation->input, 'domain_id'),
            filled(data_get($operation->input, 'edge_id')) => 'Edge '.data_get($operation->input, 'edge_id'),
            filled(data_get($operation->input, 'dns_cluster_id')) => 'DNS cluster #'.data_get($operation->input, 'dns_cluster_id'),
            default => 'Platform',
        };
    }

    /** @return array<string, mixed> */
    private function remember(string $section, OpsDashboardContext $context, int $seconds, callable $callback): array
    {
        return Cache::remember("ops-dashboard:v1:{$section}:{$context->cacheKey()}", $seconds, $callback);
    }

    /** @return array<string, mixed> */
    private function invalid(OpsDashboardContext $context): array
    {
        return ['available' => false, 'state' => 'invalid', 'error' => implode(' ', $context->errors), 'queried_at' => now('UTC')->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $message): array
    {
        return ['available' => false, 'state' => 'unavailable', 'error' => $message, 'queried_at' => now('UTC')->toIso8601String()];
    }

    private function reportFailure(string $section, Throwable $exception): void
    {
        Log::warning('Operations dashboard source unavailable.', [
            'section' => $section,
            'error_code' => class_basename($exception),
        ]);
    }
}
