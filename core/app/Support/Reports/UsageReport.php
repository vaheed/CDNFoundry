<?php

namespace App\Support\Reports;

use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\UsageRollup;
use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;

/**
 * The monthly usage report: bandwidth, 95th-percentile bandwidth, requests, and DNS queries per domain, pool, and
 * region, from rollups only. Bandwidth, requests, and the percentile come from the 5-minute usage rollup and DNS
 * queries from the control database's UsageRollup, which are the sources of the Usage and billing dashboard.
 */
final class UsageReport
{
    public function __construct(private readonly AnalyticsStore $analytics) {}

    public function forMonth(CarbonImmutable $month): Report
    {
        $from = $month->utc()->startOfMonth();
        $to = $from->addMonth();
        $domains = Domain::query()->withTrashed()->orderBy('id')->pluck('name', 'id');
        $pools = DomainEdgePlacement::query()->join('edge_pools', 'edge_pools.id', '=', 'domain_edge_placements.active_pool_id')
            ->pluck('edge_pools.name', 'domain_edge_placements.domain_id');
        $rollups = UsageRollup::query()->where('interval_start', '>=', $from)->where('interval_start', '<', $to)
            ->selectRaw('domain_id, sum(dns_queries) as dns_queries, sum(requests) as requests')->groupBy('domain_id')->get()->keyBy('domain_id');

        $rows = [];
        $totals = ['bytes' => 0, 'requests' => 0, 'dns' => 0];
        foreach ($this->analytics->usageByDomain($from, $to) as $row) {
            $id = (int) $row['domain_id'];
            $dns = (int) ($rollups->get($id)->dns_queries ?? 0);
            $rows[] = [$id, $domains[$id] ?? 'unknown', $pools[$id] ?? 'none', (int) $row['bytes'], round((float) $row['p95_bps'], 1), (int) $row['request_count'], $dns];
            $totals['bytes'] += (int) $row['bytes'];
            $totals['requests'] += (int) $row['request_count'];
            $totals['dns'] += $dns;
        }
        $regions = array_map(fn (array $row): array => [$row['region'], (int) $row['bytes'], (int) $row['request_count']], $this->analytics->usageByRegion($from, $to));

        return new Report('CDNFoundry usage report '.$from->format('Y-m'), [
            ['title' => 'Usage by domain', 'headers' => ['domain_id', 'domain', 'pool', 'bytes_sent', 'p95_bits_per_second', 'requests', 'dns_queries'], 'rows' => $rows],
            ['title' => 'Usage by region', 'headers' => ['region', 'bytes_sent', 'requests'], 'rows' => $regions],
            ['title' => 'Totals', 'headers' => ['bytes_sent', 'requests', 'dns_queries'], 'rows' => [[$totals['bytes'], $totals['requests'], $totals['dns']]]],
        ]);
    }
}
