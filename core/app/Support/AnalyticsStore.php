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

    public function summary(?Domain $domain, array $range): array
    {
        $scope = $domain === null ? '1' : 'domain_id = {domain_id:UInt64}';
        $parameters = $this->parameters($range, $domain);
        $edge = $this->query("SELECT sum(requests) AS requests, sum(bytes_in) AS bytes_in, sum(bytes_out) AS bytes_out, sumIf(requests, cache_status = 'HIT') AS cache_hits, sum(origin_errors) AS origin_errors, sum(tls_failures) AS tls_failures, sum(security_blocks) AS security_blocks FROM cdnf.edge_hourly WHERE {$scope} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64}", $parameters)[0] ?? [];
        $dns = $this->query("SELECT sum(queries) AS dns_queries FROM cdnf.dns_hourly WHERE {$scope} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64}", $parameters)[0] ?? [];
        $requests = (int) ($edge['requests'] ?? 0);

        return [...$edge, ...$dns, 'cache_ratio' => $requests === 0 ? 0 : round(((int) ($edge['cache_hits'] ?? 0)) / $requests, 6)];
    }

    public function aggregate(?Domain $domain, array $range, string $view): array
    {
        $scope = $domain === null ? '1' : 'domain_id = {domain_id:UInt64}';
        $parameters = $this->parameters($range, $domain);
        [$select, $group, $order, $table] = match ($view) {
            'timeseries', 'traffic' => ['toStartOfHour(interval_start) AS bucket, sum(requests) AS requests, sum(bytes_in) AS bytes_in, sum(bytes_out) AS bytes_out', 'bucket', 'bucket', 'edge_hourly'],
            'status-codes' => ['status, sum(requests) AS requests', 'status', 'sum(requests) DESC, status', 'edge_hourly'],
            'cache' => ['cache_status, sum(requests) AS requests', 'cache_status', 'sum(requests) DESC, cache_status', 'edge_hourly'],
            'countries' => ['country, continent, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'country, continent', 'sum(requests) DESC, country', 'edge_hourly'],
            'hostnames' => ['hostname, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'hostname', 'sum(requests) DESC, hostname', 'edge_hourly'],
            'origin' => ['sum(origin_errors) AS errors, sum(origin_latency_sum) AS latency_sum_ms, sum(origin_latency_samples) AS latency_samples, if(latency_samples = 0, 0, latency_sum_ms / latency_samples) AS average_latency_ms', '', 'errors DESC', 'edge_hourly'],
            'edges' => ['edge_id, sum(requests) AS requests, sum(bytes_out) AS bytes_out', 'edge_id', 'sum(requests) DESC, edge_id', 'edge_hourly'],
            'dns' => ['qtype, rcode, sum(queries) AS queries', 'qtype, rcode', 'sum(queries) DESC, qtype, rcode', 'dns_hourly'],
            default => throw ValidationException::withMessages(['view' => 'The analytics view is invalid.']),
        };
        if ($view === 'dns' && $domain !== null) {
            $scope = '(domain_id = {domain_id:UInt64} OR zone = {domain_name:String} OR endsWith(zone, concat(\'.\', {domain_name:String})))';
        }
        $groupSql = $group === '' ? '' : " GROUP BY {$group}";

        return $this->query("SELECT {$select} FROM cdnf.{$table} WHERE {$scope} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64}{$groupSql} ORDER BY {$order} LIMIT 1000", $parameters);
    }

    public function topUrls(Domain $domain, array $range): array
    {
        return $this->query('SELECT path, count() AS requests, sum(bytes_out) AS bytes_out FROM cdnf.edge_events WHERE domain_id = {domain_id:UInt64} AND occurred_at >= {from:DateTime64} AND occurred_at < {to:DateTime64} GROUP BY path ORDER BY requests DESC, path LIMIT 100', $this->parameters($range, $domain));
    }

    public function logs(?Domain $domain, array $range, string $stream, ?string $cursor): array
    {
        $decoded = $this->decodeCursor($cursor);
        $parameters = [...$this->parameters($range, $domain), 'cursor_time' => $decoded['occurred_at'] ?? '9999-12-31 23:59:59.999', 'cursor_id' => $decoded['event_id'] ?? 'ffffffff-ffff-ffff-ffff-ffffffffffff'];
        $scope = $domain === null ? '1' : 'domain_id = {domain_id:UInt64}';
        $cursorSql = '(occurred_at, event_id) < ({cursor_time:DateTime64}, {cursor_id:UUID})';
        if ($stream === 'dns') {
            if ($domain !== null) {
                $scope = '(domain_id = {domain_id:UInt64} OR zone = {domain_name:String} OR endsWith(zone, concat(\'.\', {domain_name:String})))';
            }
            $rows = $this->query("SELECT occurred_at, event_id, domain_id, zone, qname, qtype, rcode, client_ip, dns_cluster, country, continent, outcome FROM cdnf.dns_events WHERE {$scope} AND occurred_at >= {from:DateTime64} AND occurred_at < {to:DateTime64} AND {$cursorSql} ORDER BY occurred_at DESC, event_id DESC LIMIT 101", $parameters);
        } else {
            $filter = match ($stream) {
                'errors' => "status >= 500 OR origin_error != '' OR tls_error != ''",
                'security' => "security_action = 'block'",
                'edges' => "event_type IN ('deployment', 'health')",
                'requests' => "event_type = 'request'",
                default => throw ValidationException::withMessages(['stream' => 'The log stream is invalid.']),
            };
            $rows = $this->query("SELECT occurred_at, event_id, domain_id, hostname, method, path, status, bytes_in, bytes_out, cache_status, origin_latency_ms, origin_error, tls_error, security_action, security_reason, edge_id, client_ip, country, continent, event_type FROM cdnf.edge_events WHERE {$scope} AND occurred_at >= {from:DateTime64} AND occurred_at < {to:DateTime64} AND {$cursorSql} AND ({$filter}) ORDER BY occurred_at DESC, event_id DESC LIMIT 101", $parameters);
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

    /** @param array<int, string> $domains */
    public function usageInterval(CarbonImmutable $from, CarbonImmutable $to, array $domains): array
    {
        if ($domains === []) {
            return [];
        }
        $ids = array_keys($domains);
        $names = array_values($domains);
        $parameters = [
            'from' => $from->format('Y-m-d H:i:s.u'), 'to' => $to->format('Y-m-d H:i:s.u'),
            'domain_ids' => '['.implode(',', $ids).']',
            'domain_names' => '['.implode(',', array_map(fn (string $name): string => "'".str_replace("'", "\\'", $name)."'", $names)).']',
        ];

        return $this->query("SELECT domain_id, sum(requests) AS requests, sum(bytes_in) AS bytes_in, sum(bytes_out) AS bytes_out, sumIf(requests, cache_status = 'HIT') AS cache_hits, 0 AS dns_queries FROM cdnf.edge_hourly WHERE domain_id IN {domain_ids:Array(UInt64)} AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} GROUP BY domain_id UNION ALL WITH {domain_names:Array(String)} AS names, {domain_ids:Array(UInt64)} AS ids SELECT arrayElement(ids, arrayFirstIndex(name -> zone = name OR endsWith(zone, concat('.', name)), names)) AS mapped_domain_id, 0, 0, 0, 0, sum(queries) FROM cdnf.dns_hourly WHERE arrayFirstIndex(name -> zone = name OR endsWith(zone, concat('.', name)), names) > 0 AND interval_start >= {from:DateTime64} AND interval_start < {to:DateTime64} GROUP BY mapped_domain_id", $parameters);
    }

    public function metadata(array $range): array
    {
        $delay = app(PlatformSettings::class)->integer('telemetry', 'finalization_delay_minutes');
        $finalizedUntil = CarbonImmutable::now('UTC')->subMinutes($delay);

        return ['from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(), 'timezone' => 'UTC', 'units' => ['bandwidth' => 'bytes', 'latency' => 'milliseconds'], 'finalized_until' => $finalizedUntil->toIso8601String(), 'partial' => $range['to']->isAfter($finalizedUntil), 'sampling' => 'none'];
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

    private function parameters(array $range, ?Domain $domain): array
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
