<?php

/*
 * Deterministic ClickHouse stand-in for the disposable browser stack only (php -S router, loopback).
 * It reads the column names and aliases from each query's SELECT list and answers with rich, awkward
 * rows (many groups, long hostnames and paths, IPv6 clients, failures) so analytics screens are
 * reviewed with real-looking data. It never runs against a real ClickHouse or production.
 */

$sql = (string) file_get_contents('php://input');
$sql = preg_replace('/\s+FORMAT\s+JSONEachRow\s*$/i', '', trim($sql));
$params = [];
foreach ($_GET as $key => $value) {
    if (str_starts_with($key, 'param_')) {
        $params[substr($key, 6)] = $value;
    }
}

header('Content-Type: application/x-ndjson');
echo implode("\n", array_map(fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES), fixture_rows($sql, $params)));

/** @return list<string> top-level SELECT expressions */
function fixture_columns(string $sql): array
{
    if (! preg_match('/^\s*(?:WITH .*? )?SELECT\s+(.*?)\s+FROM\s/is', $sql, $match)) {
        return [];
    }
    $parts = [];
    $depth = 0;
    $current = '';
    foreach (str_split($match[1]) as $char) {
        $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
        if ($char === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';

            continue;
        }
        $current .= $char;
    }
    $parts[] = trim($current);

    return array_map(function (string $expression): string {
        if (preg_match('/\sAS\s+(\w+)$/i', $expression, $alias)) {
            return $alias[1];
        }

        return preg_replace('/^.*\./', '', $expression);
    }, $parts);
}

function fixture_rows(string $sql, array $params): array
{
    if (stripos($sql, 'UNION ALL') !== false) {
        return [];
    }
    $columns = fixture_columns($sql);
    if ($columns === []) {
        return [];
    }
    $from = new DateTimeImmutable(($params['from'] ?? 'now - 1 day').' UTC');
    $to = new DateTimeImmutable(($params['to'] ?? 'now').' UTC');
    $domainIds = isset($params['domain_ids']) ? array_map('intval', explode(',', trim($params['domain_ids'], '[]'))) : [(int) ($params['domain_id'] ?? 1)];
    $grouped = stripos($sql, 'GROUP BY') !== false;
    $events = stripos($sql, 'ORDER BY occurred_at DESC') !== false;
    preg_match('/LIMIT\s+(\d+)/i', $sql, $limit);
    $limit = (int) ($limit[1] ?? 100);

    if (in_array('bucket', $columns, true)) {
        $daily = stripos($sql, 'toStartOfDay') !== false;
        $step = $daily ? 86400 : 3600;
        $start = intdiv($from->getTimestamp(), $step) * $step;
        $keys = range($start, $to->getTimestamp(), $step);
    } elseif ($events) {
        $keys = range(0, min($limit, 60) - 1);
    } elseif ($grouped) {
        $keys = range(0, min($limit, in_array('domain_id', $columns, true) ? count($domainIds) : 40) - 1);
    } else {
        $keys = [0];
    }

    $rows = [];
    foreach (array_slice($keys, 0, $limit) as $index => $key) {
        $row = [];
        foreach ($columns as $column) {
            $row[$column] = fixture_value($column, $index, $key, $domainIds, $to);
        }
        $rows[] = $row;
    }

    return $rows;
}

function fixture_value(string $column, int $index, int $key, array $domainIds, DateTimeImmutable $to): mixed
{
    $wave = fn (int $base): int => (int) round($base * (1.4 + sin($index / 3)) / ($index % 7 + 1) * 3);
    $hosts = ['www.example.test', 'api.example.test', 'very-long-subdomain-name-for-a-marketing-campaign.example.test', 'xn--bcher-kva.example.test', 'cdn.shop.example.test', 'static.example.test'];
    $paths = ['/', '/index.html', '/api/v1/orders?page=2', '/assets/app.3f9a1c.js', '/images/products/a-very-long-product-image-name-with-many-words-0042.webp', '/login', '/wp-login.php', '/search?q=winter+jackets'];

    return match ($column) {
        'bucket' => gmdate('Y-m-d H:i:s', $key),
        'domain_id' => $domainIds[$index % count($domainIds)],
        'occurred_at' => $to->modify('-'.($index * 47).' seconds')->format('Y-m-d H:i:s.v'),
        'event_id' => sprintf('00000000-0000-4000-8000-%012d', $index + 1),
        'hostname' => $hosts[$index % count($hosts)],
        'path' => $paths[$index % count($paths)],
        'method' => ['GET', 'GET', 'POST', 'HEAD'][$index % 4],
        'status' => [200, 304, 404, 502, 200, 403, 500, 301][$index % 8],
        'cache_status' => ['HIT', 'MISS', 'BYPASS', 'EXPIRED', 'STALE'][$index % 5],
        'country' => ['DE', 'US', 'IR', 'FR', 'GB', 'NL', 'JP', 'BR', 'IN', 'CA'][$index % 10],
        'continent' => ['EU', 'NA', 'AS', 'EU', 'EU', 'EU', 'AS', 'SA', 'AS', 'NA'][$index % 10],
        'edge_id' => sprintf('edge-%02d-fra', $index % 5 + 1),
        'qtype' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA', 'CAA', 'SRV', 'HTTPS', 'PTR', 'DS', 'DNSKEY', 'NAPTR'][$index % 14],
        'rcode' => ['NOERROR', 'NXDOMAIN', 'NOERROR', 'SERVFAIL', 'REFUSED'][$index % 5],
        'qname' => ['www.example.test.', 'api.example.test.', '_acme-challenge.example.test.', 'missing-name.example.test.'][$index % 4],
        'zone' => 'example.test',
        'client_ip' => $index % 3 === 0 ? '2001:db8:85a3::8a2e:370:7334' : '198.51.100.'.($index % 250 + 1),
        'origin_error', 'tls_error' => $index % 4 === 1 ? 'upstream timed out (110: Connection timed out) while reading response header from upstream' : '',
        'security_action' => $index % 5 === 0 ? 'block' : 'allow',
        'security_reason' => $index % 5 === 0 ? 'rate_limit' : '',
        'event_type' => 'request',
        'encoding', 'compression_encoding' => ['br', 'gzip', 'identity'][$index % 3],
        'profile', 'compression_profile' => ['balanced', 'text_only', 'off'][$index % 3],
        'fallback', 'compression_fallback' => $index % 6 === 5 ? 'size_limit' : '',
        'outcome', 'dns_cluster' => 'primary',
        'waf_action' => $index % 5 === 0 ? 'block' : 'pass',
        'savings_ratio', 'compression_ratio', 'cache_ratio' => round(0.2 + ($index % 7) / 10, 4),
        'average_ms', 'average_latency_ms', 'origin_latency_ms' => 40 + ($index * 37) % 900,
        'requests', 'queries', 'failures', 'samples', 'reqs', 'request_count' => $wave(1800),
        'cache_hits' => $wave(1100),
        'requests_4xx' => $wave(90), 'requests_5xx' => $wave(14), 'origin_errors', 'errors', 'security_blocks', 'blocked' => $wave(9),
        'dns_queries' => $wave(5200),
        default => str_contains($column, 'bytes') || str_contains($column, 'sum') ? $wave(48_000_000) : $wave(120),
    };
}
