<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Counters for the control plane's own traffic: request latency by route, logins, and API token use by
 * ability. They live in the shared cache so every PHP-FPM worker adds to the same totals, and the metrics
 * endpoint turns them into Prometheus counters and a histogram. Every label has a fixed set of values or
 * a hard cap, so these series stay bounded no matter what a client sends.
 */
class ControlPlaneMetrics
{
    /** Upper bounds of the request latency histogram, in seconds. */
    public const LATENCY_BUCKETS = [0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0];

    /** Routes beyond this many distinct patterns share the label "other". */
    private const ROUTE_LIMIT = 200;

    private const TOKEN_ABILITIES = ['read', 'dns', 'purge', 'full'];

    public static function recordRequest(?string $routePattern, int $status, float $seconds): void
    {
        self::guard(function () use ($routePattern, $status, $seconds): void {
            $route = self::routeLabel($routePattern);
            $class = intdiv(max(100, min(599, $status)), 100).'xx';
            self::increment('requests', ['route' => $route, 'status_class' => $class]);
            self::increment('latency_count', ['route' => $route]);
            self::increment('latency_sum_us', ['route' => $route], (int) round($seconds * 1_000_000));
            foreach (self::LATENCY_BUCKETS as $bound) {
                if ($seconds <= $bound) {
                    self::increment('latency_bucket', ['route' => $route, 'le' => (string) $bound]);
                }
            }
        });
    }

    public static function recordLogin(bool $succeeded, string $surface): void
    {
        self::guard(fn () => self::increment('logins', [
            'result' => $succeeded ? 'success' : 'failure',
            'surface' => in_array($surface, ['admin', 'domain', 'api'], true) ? $surface : 'other',
        ]));
    }

    /** @param  list<string>  $abilities */
    public static function recordTokenUse(array $abilities): void
    {
        self::guard(function () use ($abilities): void {
            $labels = array_map(fn (string $ability): string => in_array($ability, self::TOKEN_ABILITIES, true) ? $ability : ($ability === '*' ? 'full' : 'other'), $abilities);
            foreach (array_unique($labels ?: ['full']) as $ability) {
                self::increment('token_use', ['ability' => $ability]);
            }
        });
    }

    /** @return list<string> Prometheus exposition lines. */
    public static function lines(): array
    {
        $lines = [];
        $requests = self::read('requests');
        if ($requests !== []) {
            $lines[] = '# HELP cdnfoundry_http_requests_total Control-plane requests by route pattern and status class.';
            $lines[] = '# TYPE cdnfoundry_http_requests_total counter';
            foreach ($requests as [$labels, $value]) {
                $lines[] = sprintf('cdnfoundry_http_requests_total{route="%s",status_class="%s"} %d', self::escape($labels['route']), $labels['status_class'], $value);
            }
        }
        $counts = self::read('latency_count');
        if ($counts !== []) {
            $sums = array_column(array_map(fn (array $row): array => [$row[0]['route'], $row[1]], self::read('latency_sum_us')), 1, 0);
            $buckets = [];
            foreach (self::read('latency_bucket') as [$labels, $value]) {
                $buckets[$labels['route']][$labels['le']] = $value;
            }
            $lines[] = '# HELP cdnfoundry_http_request_duration_seconds Control-plane request duration by route pattern.';
            $lines[] = '# TYPE cdnfoundry_http_request_duration_seconds histogram';
            foreach ($counts as [$labels, $count]) {
                $route = $labels['route'];
                $escaped = self::escape($route);
                foreach (self::LATENCY_BUCKETS as $bound) {
                    $lines[] = sprintf('cdnfoundry_http_request_duration_seconds_bucket{route="%s",le="%s"} %d', $escaped, $bound, $buckets[$route][(string) $bound] ?? 0);
                }
                $lines[] = sprintf('cdnfoundry_http_request_duration_seconds_bucket{route="%s",le="+Inf"} %d', $escaped, $count);
                $lines[] = sprintf('cdnfoundry_http_request_duration_seconds_sum{route="%s"} %.6f', $escaped, ($sums[$route] ?? 0) / 1_000_000);
                $lines[] = sprintf('cdnfoundry_http_request_duration_seconds_count{route="%s"} %d', $escaped, $count);
            }
        }
        foreach ([['logins', 'cdnfoundry_logins_total', 'Sign-in attempts by result and surface.'], ['token_use', 'cdnfoundry_api_token_requests_total', 'API requests made with a token, by ability.']] as [$family, $name, $help]) {
            $rows = self::read($family);
            if ($rows === []) {
                continue;
            }
            $lines[] = "# HELP {$name} {$help}";
            $lines[] = "# TYPE {$name} counter";
            foreach ($rows as [$labels, $value]) {
                $lines[] = sprintf('%s{%s} %d', $name, implode(',', array_map(fn (string $key, string $label): string => sprintf('%s="%s"', $key, self::escape($label)), array_keys($labels), $labels)), $value);
            }
        }

        return $lines;
    }

    private static function routeLabel(?string $pattern): string
    {
        $route = $pattern === null || $pattern === '' ? 'unmatched' : mb_substr('/'.ltrim($pattern, '/'), 0, 80);
        $known = Cache::get('cdnf_metrics:routes', []);
        if (in_array($route, $known, true)) {
            return $route;
        }
        if (count($known) >= self::ROUTE_LIMIT) {
            return 'other';
        }
        $known[] = $route;
        Cache::forever('cdnf_metrics:routes', $known);

        return $route;
    }

    /** @param  array<string, string>  $labels */
    private static function increment(string $family, array $labels, int $by = 1): void
    {
        $encoded = json_encode($labels, JSON_THROW_ON_ERROR);
        $key = 'cdnf_metrics:'.$family.':'.hash('xxh128', $encoded);
        Cache::increment($key, $by);
        $index = 'cdnf_metrics:index:'.$family;
        $known = Cache::get($index, []);
        if (! array_key_exists($key, $known)) {
            $known[$key] = $encoded;
            Cache::forever($index, $known);
        }
    }

    /** @return list<array{0: array<string, string>, 1: int}> */
    private static function read(string $family): array
    {
        $rows = [];
        foreach (Cache::get('cdnf_metrics:index:'.$family, []) as $key => $encoded) {
            $rows[] = [json_decode($encoded, true), (int) Cache::get($key, 0)];
        }
        usort($rows, fn (array $a, array $b): int => strcmp(json_encode($a[0]), json_encode($b[0])));

        return $rows;
    }

    private static function escape(string $value): string
    {
        return addcslashes(str_replace("\n", ' ', $value), '"\\');
    }

    /** Metrics are best effort; a cache fault must never fail the request being measured. */
    private static function guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
        }
    }
}
