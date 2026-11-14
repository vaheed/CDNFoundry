<?php

namespace App\Support;

use App\Exceptions\AnalyticsUnavailableException;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AnalyticsStore
{
    /** Upper bound for one domain-set query (a user's portfolio), keeping the generated DNS scope small. */
    public const MAX_SCOPE_DOMAINS = 100;

    private const AGGREGATE_MAX_DAYS = 90;

    private const RAW_MAX_HOURS = 24;

    public function range(Request $request, bool $raw = false): array
    {
        try {
            $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString())->utc() : CarbonImmutable::now('UTC');
            $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString())->utc() : ($raw ? $to->subHour() : $to->subDay());
        } catch (Throwable) {
            throw ValidationException::withMessages(['from' => 'The time range must use ISO 8601 timestamps.']);
        }
        $maximum = $raw ? self::RAW_MAX_HOURS * 3600 : self::AGGREGATE_MAX_DAYS * 86400;
        if ($from >= $to || abs($to->diffInSeconds($from)) > $maximum || $to->isAfter(CarbonImmutable::now('UTC')->addMinute())) {
            throw ValidationException::withMessages(['from' => $raw ? 'Raw-log ranges must be positive and no longer than 24 hours.' : 'Analytics ranges must be positive and no longer than 90 days.']);
        }

        return ['from' => $from, 'to' => $to, 'raw' => $raw];
    }

    public function summary(Domain|array|null $domain, array $range): array
    {
        $scope = $this->edgeScope($domain);
        $parameters = $this->parameters($range, $domain);
        $edge = $this->query("SELECT sum(requests) AS requests, sum(bytes_in) AS bytes_in, sum(bytes_out) AS bytes_out, sumIf(requests, cache_status = 'HIT') AS cache_hits, sum(origin_errors) AS origin_errors, sum(tls_failures) AS tls_failures, sum(security_blocks) AS security_blocks FROM cdnf.edge_hourly WHERE {$scope} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64}", $parameters)[0] ?? [];
        $dnsScope = $this->dnsScopeFor($domain, 'interval_start', true);
        $dns = $this->query("SELECT sum(queries) AS dns_queries FROM cdnf.dns_hourly WHERE {$dnsScope} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64}", $parameters)[0] ?? [];
        $requests = (int) ($edge['requests'] ?? 0);

        return [...$edge, ...$dns, 'cache_ratio' => $requests === 0 ? 0 : round(((int) ($edge['cache_hits'] ?? 0)) / $requests, 6)];
    }

    public function aggregate(Domain|array|null $domain, array $range, string $view): array
    {
        $scope = $this->edgeScope($domain);
        $parameters = $this->parameters($range, $domain);
        [$select, $group, $order, $table] = match ($view) {
            'timeseries', 'traffic' => ['toStartOfHour(interval_start) AS bucket, sum(requests) AS requests, sum(bytes_in) AS bytes_in, sum(bytes_out) AS bytes_out', 'bucket', 'bucket', 'edge_hourly'],
            'status-codes' => ['status, sum(requests) AS requests', 'status', 'sum(requests) DESC, status', 'edge_hourly'],
            'cache' => ['cache_status, sum(requests) AS requests', 'cache_status', 'sum(requests) DESC, cache_status', 'edge_hourly'],
            'countries' => ['country, continent, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'country, continent', 'sum(requests) DESC, country', 'edge_hourly'],
            'hostnames' => ['hostname, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'hostname', 'sum(requests) DESC, hostname', 'edge_hourly'],
            'origin' => ['sum(origin_errors) AS errors, sum(origin_latency_sum) AS latency_sum_ms, sum(origin_latency_samples) AS latency_samples, if(latency_samples = 0, 0, latency_sum_ms / latency_samples) AS average_latency_ms', '', 'errors DESC', 'edge_hourly'],
            'edges' => ['edge_id, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'edge_id', 'sum(requests) DESC, edge_id', 'edge_hourly'],
            'domains' => ["domain_id, sum(requests) AS requests, sum(bytes_in) AS bytes_in, sum(bytes_out) AS bytes_out, sumIf(requests, cache_status = 'HIT') AS cache_hits, sum(origin_errors) AS origin_errors, sum(security_blocks) AS security_blocks", 'domain_id', 'sum(requests) DESC, domain_id', 'edge_hourly'],
            'compression' => ['compression_encoding AS encoding, compression_profile AS profile, compression_fallback AS fallback, count() AS requests, sum(bytes_out) AS delivered_bytes, round(sum(bytes_out * compression_ratio)) AS identity_bytes, greatest(identity_bytes - delivered_bytes, 0) AS bytes_saved, if(identity_bytes = 0, 0, round(bytes_saved / identity_bytes, 6)) AS savings_ratio', 'encoding, profile, fallback', 'bytes_saved DESC, encoding, profile, fallback', 'edge_events'],
            'volume-edges' => ['edge_id, sum(requests) AS requests, sum(bytes_in) AS upload_bytes, sum(bytes_sent) AS download_bytes', 'edge_id', 'download_bytes DESC, edge_id', 'edge_volume_hourly'],
            'volume-cells' => ['cell, edge_id, sum(requests) AS requests, sum(bytes_in) AS upload_bytes, sum(bytes_sent) AS download_bytes', 'cell, edge_id', 'download_bytes DESC, cell, edge_id', 'edge_volume_hourly'],
            'volume-domains' => ['domain_id, sum(requests) AS requests, sum(bytes_in) AS upload_bytes, sum(bytes_sent) AS download_bytes', 'domain_id', 'download_bytes DESC, domain_id', 'edge_volume_hourly'],
            'volume-countries' => ['country, sum(requests) AS requests, sum(bytes_in) AS upload_bytes, sum(bytes_sent) AS download_bytes', 'country', 'download_bytes DESC, country', 'edge_volume_hourly'],
            'security-bands' => ['attack_band, sum(requests) AS requests, sumIf(requests, action != \'allowed\') AS mitigated', 'attack_band', 'attack_band', 'security_hourly'],
            'security-actions' => ['action, sum(requests) AS requests, sumIf(requests, action != \'allowed\') AS mitigated', 'action', 'sum(requests) DESC, action', 'security_hourly'],
            'security-countries' => ['country, sum(requests) AS requests, sumIf(requests, action != \'allowed\') AS mitigated', 'country', 'mitigated DESC, sum(requests) DESC, country', 'security_hourly'],
            'security-asns' => ['asn, sum(requests) AS requests, sumIf(requests, action != \'allowed\') AS mitigated', 'asn', 'mitigated DESC, sum(requests) DESC, asn', 'security_hourly'],
            'security-paths' => ["path, count() AS requests, countIf(security_action = 'block' OR security_rule != '') AS mitigated", 'path', 'mitigated DESC, requests DESC, path', 'edge_events'],
            'security-agents' => ["user_agent, count() AS requests, countIf(security_action = 'block' OR security_rule != '') AS mitigated", 'user_agent', 'mitigated DESC, requests DESC, user_agent', 'edge_events'],
            'dns' => ['qtype, rcode, sum(queries) AS queries', 'qtype, rcode', 'sum(queries) DESC, qtype, rcode', 'dns_hourly'],
            default => throw ValidationException::withMessages(['view' => 'The analytics view is invalid.']),
        };
        if ($view === 'dns') {
            $scope = $this->dnsScopeFor($domain, 'interval_start', true);
        }
        $groupSql = $group === '' ? '' : " GROUP BY {$group}";

        $timeColumn = $table === 'edge_events' ? 'occurred_at' : 'interval_start';
        if ($table === 'edge_events' && str_starts_with($view, 'security-')) {
            $scope .= " AND event_type = 'request'";
        }

        return $this->query("SELECT {$select} FROM cdnf.{$table} WHERE {$scope} AND {$timeColumn} >= {from:DateTime64} AND {$timeColumn} < {to:DateTime64}{$groupSql} ORDER BY {$order} LIMIT 1000", $parameters);
    }

    /**
     * Return the bounded hourly evidence used by the administrator operations
     * overview. Percentile latency is intentionally absent because the current
     * aggregate schema stores only a latency sum and sample count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function operationalTrafficSeries(Domain|array|null $domain, array $range, ?string $edgeId = null, string $bucket = 'hour'): array
    {
        [$bucketSql, $limit] = $bucket === 'day' ? ['toStartOfDay(interval_start)', 100] : ['toStartOfHour(interval_start)', 169];
        $scope = $this->edgeScope($domain);
        if ($edgeId !== null) {
            $scope .= ' AND edge_id = {edge_id:String}';
        }

        return $this->query(
            "SELECT {$bucketSql} AS bucket,
                sum(requests) AS requests,
                sum(bytes_in) AS bytes_in,
                sum(bytes_out) AS bytes_out,
                sumIf(requests, upper(cache_status) = 'HIT') AS cache_hits,
                sumIf(requests, upper(cache_status) = 'MISS') AS cache_misses,
                sumIf(requests, upper(cache_status) = 'BYPASS') AS cache_bypass,
                sumIf(requests, upper(cache_status) = 'STALE') AS cache_stale,
                sumIf(bytes_out, upper(cache_status) = 'HIT') AS cache_bytes_out,
                sumIf(requests, status >= 400 AND status < 500) AS requests_4xx,
                sumIf(requests, status >= 500 AND status < 600) AS requests_5xx,
                sum(origin_errors) AS origin_errors,
                sum(origin_latency_sum) AS origin_latency_sum_ms,
                sum(origin_latency_samples) AS origin_latency_samples
            FROM cdnf.edge_hourly
            WHERE {$scope} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64}
            GROUP BY bucket ORDER BY bucket LIMIT {$limit}",
            array_filter([...$this->parameters($range, $domain), 'edge_id' => $edgeId], fn ($value): bool => $value !== null),
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function operationalDnsSeries(Domain|array|null $domain, array $range): array
    {
        $scope = $this->dnsScopeFor($domain, 'interval_start', true);

        return $this->query(
            "SELECT toStartOfHour(interval_start) AS bucket,
                sum(queries) AS queries,
                sumIf(queries, upper(rcode) = 'NOERROR') AS successful,
                sumIf(queries, upper(rcode) = 'SERVFAIL') AS servfail,
                sumIf(queries, upper(rcode) = 'NXDOMAIN') AS nxdomain,
                sumIf(queries, upper(rcode) NOT IN ('NOERROR', 'SERVFAIL', 'NXDOMAIN')) AS other
            FROM cdnf.dns_hourly
            WHERE {$scope} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64}
            GROUP BY bucket ORDER BY bucket LIMIT 169",
            $this->parameters($range, $domain),
        );
    }

    /**
     * Detail tables behind a dashboard drill-down. Hourly aggregates cover the whole range; the raw
     * event tables are bounded to the raw window the caller passes. Every query has a small LIMIT.
     *
     * @return list<array{title: string, description: string, columns: array<string, string>, rows: list<array<string, mixed>>}>
     */
    public function investigation(Domain|array|null $domain, array $range, array $rawRange, string $view, ?string $statusFamily = null): array
    {
        $scope = $this->edgeScope($domain);
        $aggregate = fn (string $select, string $group, string $order, string $extra = '1'): array => $this->query(
            "SELECT {$select} FROM cdnf.edge_hourly WHERE {$scope} AND ({$extra}) AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} GROUP BY {$group} ORDER BY {$order} LIMIT 15",
            $this->parameters($range, $domain),
        );
        $raw = fn (string $select, string $group, string $order, string $extra = '1', string $source = 'edge_events', ?string $sourceScope = null): array => $this->query(
            "SELECT {$select} FROM cdnf.{$source} WHERE ".($sourceScope ?? $scope)." AND ({$extra}) AND occurred_at >= {from:DateTime64} AND occurred_at < {to:DateTime64} GROUP BY {$group} ORDER BY {$order} LIMIT 15",
            $this->parameters($rawRange, $domain),
        );
        $status = match ($statusFamily) {
            '4xx' => 'status >= 400 AND status < 500',
            '5xx' => 'status >= 500 AND status < 600',
            default => '1',
        };
        $window = 'the whole range';
        $rawWindow = 'the latest '.max(1, (int) ceil($rawRange['from']->diffInHours($rawRange['to']))).' hours';

        return match ($view) {
            'traffic' => [
                ['title' => 'Busiest hostnames', 'description' => "Requests and egress by hostname over {$window}.", 'columns' => ['hostname' => 'Hostname', 'requests' => 'Requests', 'bytes_out' => 'Egress bytes'],
                    'rows' => $aggregate('hostname, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'hostname', 'requests DESC', "hostname != '' AND {$status}")],
                ['title' => 'Status codes', 'description' => "Responses by HTTP status over {$window}.", 'columns' => ['status' => 'Status', 'requests' => 'Requests'],
                    'rows' => $aggregate('status, sum(requests) AS requests', 'status', 'requests DESC', $status)],
                ['title' => 'Countries', 'description' => "Where requests came from over {$window}.", 'columns' => ['country' => 'Country', 'continent' => 'Continent', 'requests' => 'Requests', 'bytes_out' => 'Egress bytes'],
                    'rows' => $aggregate('country, continent, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'country, continent', 'requests DESC', $status)],
                ['title' => 'Edges', 'description' => "Requests served by each edge over {$window}.", 'columns' => ['edge_id' => 'Edge', 'requests' => 'Requests', 'bytes_out' => 'Egress bytes'],
                    'rows' => $aggregate('edge_id, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'edge_id', 'requests DESC', $status)],
                ['title' => 'Most requested paths', 'description' => "Paths by request count in {$rawWindow}.", 'columns' => ['hostname' => 'Hostname', 'path' => 'Path', 'requests' => 'Requests', 'bytes_out' => 'Egress bytes'],
                    'rows' => $raw('hostname, path, count() AS requests, sum(bytes_out) AS bytes_out', 'hostname, path', 'requests DESC', "event_type = 'request' AND {$status}")],
            ],
            'cache' => [
                ['title' => 'Cache outcomes', 'description' => "How requests were answered over {$window}.", 'columns' => ['cache_status' => 'Cache status', 'requests' => 'Requests', 'bytes_out' => 'Egress bytes'],
                    'rows' => $aggregate('cache_status, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'cache_status', 'requests DESC')],
                ['title' => 'Hostnames with the most misses and bypasses', 'description' => "Hostnames that reached the origin most often over {$window}.", 'columns' => ['hostname' => 'Hostname', 'requests' => 'Uncached requests'],
                    'rows' => $aggregate('hostname, sum(requests) AS requests', 'hostname', 'requests DESC', "hostname != '' AND upper(cache_status) IN ('MISS', 'BYPASS', 'EXPIRED')")],
                ['title' => 'Paths that miss or bypass the cache', 'description' => "Candidates for a longer cache time in {$rawWindow}.", 'columns' => ['hostname' => 'Hostname', 'path' => 'Path', 'cache_status' => 'Cache status', 'requests' => 'Requests'],
                    'rows' => $raw('hostname, path, cache_status, count() AS requests', 'hostname, path, cache_status', 'requests DESC', "event_type = 'request' AND upper(cache_status) IN ('MISS', 'BYPASS', 'EXPIRED')")],
            ],
            'origin' => [
                ['title' => 'Origin failures by hostname', 'description' => "Requests that failed at the origin or returned 5xx in {$rawWindow}.", 'columns' => ['hostname' => 'Hostname', 'failures' => 'Failures', 'origin_error' => 'Most common error'],
                    'rows' => $raw('hostname, count() AS failures, topK(1)(origin_error)[1] AS origin_error', 'hostname', 'failures DESC', "event_type = 'request' AND (status >= 500 OR origin_error != '')")],
                ['title' => 'Origin error reasons', 'description' => "What the edge recorded when the origin failed, in {$rawWindow}.", 'columns' => ['origin_error' => 'Reason', 'requests' => 'Requests'],
                    'rows' => $raw('origin_error, count() AS requests', 'origin_error', 'requests DESC', "origin_error != ''")],
                ['title' => 'Slowest origins', 'description' => "Average origin response time per hostname in {$rawWindow}.", 'columns' => ['hostname' => 'Hostname', 'average_ms' => 'Average (ms)', 'samples' => 'Samples'],
                    'rows' => $raw('hostname, round(avg(origin_latency_ms)) AS average_ms, count() AS samples', 'hostname', 'average_ms DESC', 'origin_latency_ms IS NOT NULL')],
                ['title' => 'Failed paths', 'description' => "Paths with the most 5xx responses in {$rawWindow}.", 'columns' => ['hostname' => 'Hostname', 'path' => 'Path', 'status' => 'Status', 'requests' => 'Requests'],
                    'rows' => $raw('hostname, path, status, count() AS requests', 'hostname, path, status', 'requests DESC', "event_type = 'request' AND status >= 500")],
            ],
            'dns' => [
                ['title' => 'Query types and responses', 'description' => "DNS answers by type and response code over {$window}.", 'columns' => ['qtype' => 'Type', 'rcode' => 'Response', 'queries' => 'Queries'],
                    'rows' => $this->query('SELECT qtype, rcode, sum(queries) AS queries FROM cdnf.dns_hourly WHERE '.$this->dnsScopeFor($domain, 'interval_start', true).' AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} GROUP BY qtype, rcode ORDER BY queries DESC LIMIT 15', $this->parameters($range, $domain))],
                ['title' => 'Most queried names', 'description' => "Names queried most in {$rawWindow}.", 'columns' => ['qname' => 'Name', 'qtype' => 'Type', 'queries' => 'Queries'],
                    'rows' => $raw('qname, qtype, count() AS queries', 'qname, qtype', 'queries DESC', '1', 'dns_events', $this->dnsScopeFor($domain, 'occurred_at', false))],
                ['title' => 'Failing names', 'description' => "Names answered with SERVFAIL or REFUSED in {$rawWindow}.", 'columns' => ['qname' => 'Name', 'rcode' => 'Response', 'queries' => 'Queries'],
                    'rows' => $raw('qname, rcode, count() AS queries', 'qname, rcode', 'queries DESC', "upper(rcode) IN ('SERVFAIL', 'REFUSED')", 'dns_events', $this->dnsScopeFor($domain, 'occurred_at', false))],
                ['title' => 'Resolver networks', 'description' => "Countries the queries came from in {$rawWindow}.", 'columns' => ['country' => 'Country', 'queries' => 'Queries'],
                    'rows' => $raw('country, count() AS queries', 'country', 'queries DESC', '1', 'dns_events', $this->dnsScopeFor($domain, 'occurred_at', false))],
            ],
            default => throw ValidationException::withMessages(['view' => 'The analytics view is invalid.']),
        };
    }

    public function topUrls(Domain|array $domain, array $range): array
    {
        return $this->query('SELECT path, count() AS requests, sum(bytes_out) AS bytes_out FROM cdnf.edge_events WHERE '.$this->edgeScope($domain).' AND occurred_at >= {from:DateTime64} AND occurred_at < {to:DateTime64} GROUP BY path ORDER BY requests DESC, path LIMIT 100', $this->parameters($range, $domain));
    }

    public function logs(Domain|array|null $domain, array $range, string $stream, ?string $cursor, ?string $statusFamily = null): array
    {
        $decoded = $this->decodeCursor($cursor);
        $parameters = [...$this->parameters($range, $domain), 'cursor_time' => $decoded['occurred_at'] ?? '9999-12-31 23:59:59.999', 'cursor_id' => $decoded['event_id'] ?? 'ffffffff-ffff-ffff-ffff-ffffffffffff'];
        $scope = $this->edgeScope($domain);
        $cursorSql = '(occurred_at, event_id) < ({cursor_time:DateTime64}, {cursor_id:UUID})';
        if ($stream === 'dns') {
            $scope = $this->dnsScopeFor($domain, 'occurred_at', false);
            $rows = $this->query("SELECT occurred_at, event_id, domain_id, zone, qname, qtype, rcode, client_ip, dns_cluster, country, continent, outcome FROM cdnf.dns_events WHERE {$scope} AND occurred_at >= {from:DateTime64} AND occurred_at < {to:DateTime64} AND {$cursorSql} ORDER BY occurred_at DESC, event_id DESC LIMIT 101", $parameters);
        } else {
            $filter = match ($stream) {
                'errors' => "status >= 500 OR origin_error != '' OR tls_error != ''",
                'security' => "security_action = 'block' OR waf_action IN ('detect', 'block', 'excluded')",
                'edges' => "event_type IN ('deployment', 'health')",
                'requests' => "event_type = 'request'",
                default => throw ValidationException::withMessages(['stream' => 'The log stream is invalid.']),
            };
            $statusFilter = match ($statusFamily) {
                null => null,
                '4xx' => 'status >= 400 AND status < 500',
                '5xx' => 'status >= 500 AND status < 600',
                default => throw ValidationException::withMessages(['status_family' => 'The HTTP status family is invalid.']),
            };
            if ($statusFilter !== null) {
                $filter = $stream === 'errors' && $statusFamily === '4xx'
                    ? $statusFilter
                    : "({$filter}) AND {$statusFilter}";
            }
            $rows = $this->query("SELECT occurred_at, event_id, domain_id, hostname, method, path, status, bytes_in, bytes_out, cache_status, origin_latency_ms, origin_error, tls_error, security_action, security_reason, edge_id, client_ip, country, continent, event_type, compression_encoding, compression_ratio, compression_profile, compression_fallback, waf_profile, waf_rule_id, waf_score, waf_action, waf_processing_us, waf_body_limit, waf_exclusion_id FROM cdnf.edge_events WHERE {$scope} AND occurred_at >= {from:DateTime64} AND occurred_at < {to:DateTime64} AND {$cursorSql} AND ({$filter}) ORDER BY occurred_at DESC, event_id DESC LIMIT 101", $parameters);
        }
        $hasMore = count($rows) > 100;
        $rows = array_slice($rows, 0, 100);
        foreach ($rows as &$row) {
            if (isset($row['client_ip'])) {
                $row['client_ip'] = $this->maskAddress((string) $row['client_ip']);
            }
        }
        $last = end($rows);

        return ['items' => $rows, 'next_cursor' => $hasMore && is_array($last) ? $this->encodeCursor($last) : null];
    }

    /** The most mitigated requests one list returns. The list is the newest of the window, not every mitigated request. */
    public const MITIGATIONS_LIMIT = 500;

    /**
     * Mitigated requests of one domain, newest first: blocked, challenged, or logged by a rule. Only stored request events can be
     * listed, so the list is bounded by the event retention and by MITIGATIONS_LIMIT.
     *
     * @param  array{action?: ?string, rule?: ?string, reason?: ?string, score_max?: ?int, path?: ?string, country?: ?string, asn?: ?int, user_agent?: ?string, request_id?: ?string}  $filters
     */
    public function mitigations(Domain $domain, array $range, array $filters): array
    {
        $parameters = [...$this->parameters($range, $domain), 'limit' => self::MITIGATIONS_LIMIT];
        $where = ["event_type = 'request'", "(security_action = 'block' OR security_rule != '')"];
        $equals = ['rule' => ['security_rule', 'String'], 'reason' => ['security_reason', 'String'], 'country' => ['country', 'String'], 'request_id' => ['request_id', 'String']];
        foreach ($equals as $name => [$column, $type]) {
            if (filled($filters[$name] ?? null)) {
                $where[] = "{$column} = {{$name}:{$type}}";
                $parameters[$name] = (string) $filters[$name];
            }
        }
        foreach (['path' => 'path', 'user_agent' => 'user_agent'] as $name => $column) {
            if (filled($filters[$name] ?? null)) {
                $where[] = "positionCaseInsensitiveUTF8({$column}, {{$name}:String}) > 0";
                $parameters[$name] = (string) $filters[$name];
            }
        }
        if (filled($filters['asn'] ?? null)) {
            $where[] = 'asn = {asn:UInt32}';
            $parameters['asn'] = (int) $filters['asn'];
        }
        if (filled($filters['score_max'] ?? null)) {
            $where[] = 'attack_score BETWEEN 1 AND {score_max:UInt8}';
            $parameters['score_max'] = (int) $filters['score_max'];
        }
        $where[] = match ($filters['action'] ?? null) {
            'blocked' => "security_action = 'block'",
            'logged' => "security_action != 'block'",
            default => '1',
        };
        $rows = $this->query('SELECT occurred_at, event_id, hostname, method, path, status, security_action, security_reason, security_rule, attack_score, country, asn, user_agent, request_id, client_ip FROM cdnf.edge_events WHERE '.$this->edgeScope($domain).' AND occurred_at >= {from:DateTime64} AND occurred_at < {to:DateTime64} AND '.implode(' AND ', $where).' ORDER BY occurred_at DESC, event_id DESC LIMIT {limit:UInt32}', $parameters);
        foreach ($rows as &$row) {
            $row['client_ip'] = $this->maskAddress((string) ($row['client_ip'] ?? ''));
        }

        return $rows;
    }

    /** @param array<int, string> $domains */
    public function usageInterval(CarbonImmutable $from, CarbonImmutable $to, array $domains): array
    {
        if ($domains === []) {
            return [];
        }
        // The most specific domain must win when zones are nested.
        uasort($domains, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $ids = array_keys($domains);
        $names = array_values($domains);
        $parameters = [
            'from' => $from->format('Y-m-d H:i:s.u'), 'to' => $to->format('Y-m-d H:i:s.u'),
            'domain_ids' => '['.implode(',', $ids).']',
            'domain_names' => '['.implode(',', array_map(fn (string $name): string => "'".str_replace("'", "\\'", $name)."'", $names)).']',
        ];

        return $this->query("SELECT domain_id, sum(requests) AS requests, sum(bytes_in) AS bytes_in, sum(bytes_out) AS bytes_out, sumIf(requests, cache_status = 'HIT') AS cache_hits, 0 AS dns_queries FROM cdnf.edge_hourly WHERE domain_id IN {domain_ids:Array(UInt64)} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} GROUP BY domain_id UNION ALL WITH {domain_names:Array(String)} AS names, {domain_ids:Array(UInt64)} AS ids SELECT arrayElement(ids, arrayFirstIndex(name -> zone = name OR endsWith(zone, concat('.', name)), names)) AS mapped_domain_id, 0, 0, 0, 0, sum(queries) FROM cdnf.dns_hourly WHERE arrayFirstIndex(name -> zone = name OR endsWith(zone, concat('.', name)), names) > 0 AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} GROUP BY mapped_domain_id", $parameters);
    }

    /**
     * Bandwidth (bytes sent, headers included), requests, and 95th-percentile bandwidth per domain, from the
     * 5-minute usage rollup. The Usage and billing dashboard runs the same query, so a report and the dashboard
     * agree for the same range.
     *
     * @return list<array{domain_id: int|string, bytes: int|string, p95_bps: float|string, request_count: int|string}>
     */
    public function usageByDomain(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->query('SELECT domain_id, sum(sent) AS bytes, quantile(0.95)(bps) AS p95_bps, sum(reqs) AS request_count FROM '
            .'(SELECT domain_id, interval_start, sum(bytes_sent) AS sent, sum(requests) AS reqs, 8 * sum(bytes_sent) / 300 AS bps FROM cdnf.edge_usage_5m '
            .'WHERE interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} GROUP BY domain_id, interval_start) GROUP BY domain_id ORDER BY bytes DESC LIMIT 10000',
            ['from' => $from->format('Y-m-d H:i:s.u'), 'to' => $to->format('Y-m-d H:i:s.u')]);
    }

    /** @return list<array{region: string, bytes: int|string, request_count: int|string}> */
    public function usageByRegion(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->query('SELECT region, sum(bytes_sent) AS bytes, sum(requests) AS request_count FROM cdnf.edge_usage_5m '
            .'WHERE interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} GROUP BY region ORDER BY bytes DESC LIMIT 1000',
            ['from' => $from->format('Y-m-d H:i:s.u'), 'to' => $to->format('Y-m-d H:i:s.u')]);
    }

    /** @return list<array{hostname: string, errors: int|string}> */
    public function topErrorHostnames(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->query("SELECT hostname, sumIf(requests, status >= 500) AS errors FROM cdnf.edge_traffic_hourly WHERE interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} AND hostname != '' GROUP BY hostname HAVING errors > 0 ORDER BY errors DESC LIMIT 10",
            ['from' => $from->format('Y-m-d H:i:s.u'), 'to' => $to->format('Y-m-d H:i:s.u')]);
    }

    /** @return list<array{domain_id: int|string, requests: int|string, origin_errors: int|string}> */
    public function originErrorsByDomain(CarbonImmutable $from, CarbonImmutable $to, int $minimumRequests): array
    {
        return $this->query('SELECT domain_id, sum(requests) AS requests, sum(origin_errors) AS origin_errors FROM cdnf.edge_traffic_1m WHERE interval_start >= {from:DateTime} AND interval_start < {to:DateTime} AND domain_id != 0 GROUP BY domain_id HAVING requests >= {minimum:UInt64} AND origin_errors > 0 ORDER BY origin_errors DESC LIMIT 1000',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s'), 'minimum' => $minimumRequests]);
    }

    /**
     * The next rows of one domain's dataset after a cursor, oldest first. The caller supplies only allowlisted fields and an
     * integer domain id; events newer than `$until` are left for the next run so late-arriving inserts are not skipped.
     *
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    public function rowsAfter(string $table, string $condition, array $fields, int $domainId, string $afterAt, string $afterId, CarbonImmutable $until, int $limit): array
    {
        $columns = implode(', ', array_map(fn (string $field): string => $field, $fields));

        return $this->query("SELECT toString(occurred_at) AS occurred_at, toString(event_id) AS event_id, {$columns} FROM {$table} WHERE domain_id = {domain:UInt64} AND {$condition} "
            .'AND (occurred_at, event_id) > ({afterAt:DateTime64(3)}, {afterId:UUID}) AND occurred_at <= {until:DateTime64(3)} ORDER BY occurred_at, event_id LIMIT {limit:UInt32}',
            ['domain' => $domainId, 'afterAt' => $afterAt, 'afterId' => $afterId, 'until' => $until->format('Y-m-d H:i:s.v'), 'limit' => $limit]);
    }

    /**
     * The scripts and connections browsers reported for these domains since a time, one row per page and resource.
     *
     * @param  list<int>  $domainIds
     * @return list<array{domain_id: int|string, kind: string, page: string, resource: string, first_seen: string, last_seen: string}>
     */
    public function clientReports(array $domainIds, CarbonImmutable $from): array
    {
        $ids = implode(',', array_map('intval', $domainIds));
        if ($ids === '') {
            return [];
        }

        return $this->query("SELECT domain_id, splitByChar('|', client_report)[1] AS kind, splitByChar('|', client_report)[2] AS page, splitByChar('|', client_report)[3] AS resource, toString(min(occurred_at)) AS first_seen, toString(max(occurred_at)) AS last_seen FROM cdnf.edge_events WHERE domain_id IN ({$ids}) AND client_report != '' AND occurred_at >= {from:DateTime64} GROUP BY domain_id, kind, page, resource HAVING kind != '' AND page != '' AND resource != '' ORDER BY domain_id, first_seen LIMIT 5000",
            ['from' => $from->format('Y-m-d H:i:s.u')]);
    }

    /** @return list<array{hostname: string, blocked: int|string}> */
    public function topAttackedHostnames(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->query("SELECT hostname, sum(security_blocks) AS blocked FROM cdnf.edge_traffic_hourly WHERE interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} AND hostname != '' GROUP BY hostname HAVING blocked > 0 ORDER BY blocked DESC LIMIT 10",
            ['from' => $from->format('Y-m-d H:i:s.u'), 'to' => $to->format('Y-m-d H:i:s.u')]);
    }

    public function metadata(array $range): array
    {
        $delay = app(PlatformSettings::class)->integer('telemetry', 'finalization_delay_minutes');
        $finalizedUntil = CarbonImmutable::now('UTC')->subMinutes($delay);

        return [
            'from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(), 'timezone' => 'UTC',
            'units' => ['bandwidth' => 'bytes', 'latency' => 'milliseconds'],
            'finalized_until' => $finalizedUntil->toIso8601String(), 'finalization_delay_minutes' => $delay,
            'partial' => $range['to']->isAfter($finalizedUntil), 'sampling' => 'none',
        ];
    }

    private function query(string $sql, array $parameters): array
    {
        $configuration = config('services.clickhouse');
        $query = ['database' => $configuration['database'], 'default_format' => 'JSONEachRow', 'max_execution_time' => $configuration['max_execution_time'], 'max_memory_usage' => $configuration['max_memory_usage'], 'max_rows_to_read' => $configuration['max_rows_to_read'], 'max_result_rows' => $configuration['max_result_rows'], 'prefer_column_name_to_alias' => 1];
        foreach ($parameters as $key => $value) {
            $query["param_{$key}"] = $value;
        }
        try {
            $response = Http::withBasicAuth($configuration['username'], $configuration['password'])
                ->connectTimeout($configuration['connect_timeout'])->timeout($configuration['timeout'])
                ->withQueryParameters($query)->withBody($sql.' FORMAT JSONEachRow', 'text/plain')
                ->post(rtrim($configuration['url'], '/'));
            if (! $response->successful()) {
                throw new AnalyticsUnavailableException;
            }
            $body = trim($response->body());

            return $body === '' ? [] : collect(explode("\n", $body))->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))->all();
        } catch (AnalyticsUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AnalyticsUnavailableException(previous: $exception);
        }
    }

    /**
     * DNS telemetry carries no domain id, so ownership comes from the queried
     * zone. A tenant sees its own zone and subdomains, except names under
     * another registered domain (current or deleted) and anything logged before
     * the current owner's domain row existed.
     */
    private function dnsScope(string $timeColumn, bool $hourly, string $suffix = ''): string
    {
        $since = $hourly ? "toStartOfHour({owned_since{$suffix}:DateTime64})" : "{owned_since{$suffix}:DateTime64}";

        return "((zone = {domain_name{$suffix}:String} OR endsWith(zone, concat('.', {domain_name{$suffix}:String}))) "
            ."AND NOT arrayExists(child -> zone = child OR endsWith(zone, concat('.', child)), {excluded_zones{$suffix}:Array(String)}) "
            ."AND {$timeColumn} >= {$since})";
    }

    /**
     * Edge events carry the domain id. A domain set (a user's domains) is bounded by MAX_SCOPE_DOMAINS.
     *
     * @param  Domain|array<int, Domain>|null  $domain
     */
    private function edgeScope(Domain|array|null $domain): string
    {
        return match (true) {
            $domain === null => '1',
            $domain instanceof Domain => 'domain_id = {domain_id:UInt64}',
            $this->domainSet($domain) === [] => '0',
            default => 'domain_id IN {domain_ids:Array(UInt64)}',
        };
    }

    /**
     * DNS scope for one domain or a domain set: each domain keeps its own zone, nested-domain exclusions, and
     * ownership start, so a set never widens what any single domain would show.
     *
     * @param  Domain|array<int, Domain>|null  $domain
     */
    private function dnsScopeFor(Domain|array|null $domain, string $timeColumn, bool $hourly): string
    {
        if ($domain === null) {
            return '1';
        }
        if ($domain instanceof Domain) {
            return $this->dnsScope($timeColumn, $hourly);
        }
        $set = $this->domainSet($domain);

        return $set === [] ? '0' : '('.implode(' OR ', array_map(fn (int $index): string => $this->dnsScope($timeColumn, $hourly, "_{$index}"), array_keys($set))).')';
    }

    /**
     * @param  array<int, Domain>  $domains
     * @return list<Domain>
     */
    private function domainSet(array $domains): array
    {
        $set = array_values(array_filter($domains, fn (mixed $domain): bool => $domain instanceof Domain));
        if (count($set) > self::MAX_SCOPE_DOMAINS) {
            throw ValidationException::withMessages(['domain_id' => 'Select at most '.self::MAX_SCOPE_DOMAINS.' domains.']);
        }

        return $set;
    }

    private function parameters(array $range, Domain|array|null $domain): array
    {
        if (is_array($domain)) {
            return $this->setParameters($range, $this->domainSet($domain));
        }
        $dns = [];
        if ($domain !== null) {
            $children = Domain::withTrashed()->where('name', 'like', '%.'.addcslashes($domain->name, '%_\\'))->limit(1000)->pluck('name');
            $dns = [
                'excluded_zones' => '['.$children->map(fn (string $name): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $name)."'")->implode(',').']',
                'owned_since' => ($domain->created_at ?? now())->clone()->utc()->format('Y-m-d H:i:s.u'),
            ];
        }

        return [...$dns, ...$this->baseParameters($range, $domain)];
    }

    /** @param list<Domain> $domains */
    private function setParameters(array $range, array $domains): array
    {
        $parameters = $this->baseParameters($range, null);
        if ($domains === []) {
            return $parameters;
        }
        $names = array_map(fn (Domain $domain): string => $domain->name, $domains);
        $children = Domain::withTrashed()
            ->where(function ($query) use ($names): void {
                foreach ($names as $name) {
                    $query->orWhere('name', 'like', '%.'.addcslashes($name, '%_\\'));
                }
            })
            ->limit(5000)->pluck('name');
        $parameters['domain_ids'] = '['.implode(',', array_map(fn (Domain $domain): int => (int) $domain->getKey(), $domains)).']';
        foreach ($domains as $index => $domain) {
            $nested = $children->filter(fn (string $child): bool => str_ends_with($child, '.'.$domain->name));
            $parameters["domain_name_{$index}"] = $domain->name;
            $parameters["excluded_zones_{$index}"] = '['.$nested->map(fn (string $name): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $name)."'")->implode(',').']';
            $parameters["owned_since_{$index}"] = ($domain->created_at ?? now())->clone()->utc()->format('Y-m-d H:i:s.u');
        }

        return $parameters;
    }

    private function baseParameters(array $range, ?Domain $domain): array
    {
        return array_filter(['from' => $range['from']->format('Y-m-d H:i:s.u'), 'to' => $range['to']->format('Y-m-d H:i:s.u'), 'domain_id' => $domain?->getKey(), 'domain_name' => $domain?->name], fn ($value): bool => $value !== null);
    }

    private function maskAddress(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $bits = app(PlatformSettings::class)->integer('telemetry', 'ipv4_mask_bits');
            $packed = inet_pton($address);
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bits = app(PlatformSettings::class)->integer('telemetry', 'ipv6_mask_bits');
            $packed = inet_pton($address);
        } else {
            return 'unknown';
        }
        for ($bit = $bits; $bit < strlen($packed) * 8; $bit++) {
            $byte = intdiv($bit, 8);
            $packed[$byte] = chr(ord($packed[$byte]) & ~(1 << (7 - ($bit % 8))));
        }

        return inet_ntop($packed)."/{$bits}";
    }

    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [];
        }
        $decoded = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
        if (! is_array($decoded) || ! isset($decoded['occurred_at'], $decoded['event_id'])) {
            throw ValidationException::withMessages(['cursor' => 'The log cursor is invalid.']);
        }

        return $decoded;
    }

    private function encodeCursor(array $row): string
    {
        return rtrim(strtr(base64_encode(json_encode(['occurred_at' => $row['occurred_at'], 'event_id' => $row['event_id']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
