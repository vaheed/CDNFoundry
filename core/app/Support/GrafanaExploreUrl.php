<?php

namespace App\Support;

use Throwable;

final class GrafanaExploreUrl
{
    public function configuredOperationalLogs(): ?string
    {
        $configured = null;
        try {
            $configured = app(PlatformSettings::class)->get('observability', 'grafana_explore_url');
        } catch (Throwable) {
            // Database-backed settings may be unavailable during installation
            // or an observability outage. The deployment default remains safe.
        }

        return $this->operationalLogs(filled($configured) ? (string) $configured : config('services.grafana.explore_url'));
    }

    private const DEFAULT_EXPRESSION = '{environment=~"production|development"} | json';

    public function operationalLogs(?string $configuredUrl): ?string
    {
        if (blank($configuredUrl)) {
            return null;
        }

        $parts = parse_url($configuredUrl);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        if (! $this->containsExpression($query['left'] ?? null) && ! $this->containsExpression($query['panes'] ?? null)) {
            unset($query['left']);
            $query['schemaVersion'] = '1';
            $query['panes'] = json_encode([
                'cdnfoundry' => [
                    'datasource' => 'loki',
                    'queries' => [[
                        'refId' => 'A',
                        'expr' => self::DEFAULT_EXPRESSION,
                        'queryType' => 'range',
                    ]],
                    'range' => ['from' => 'now-1h', 'to' => 'now'],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/explore';
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $parts['scheme'].'://'.$parts['host'].$port.$path
            .($queryString === '' ? '' : '?'.$queryString)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function containsExpression(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return false;
        }

        return collect($decoded['queries'] ?? $decoded)
            ->flatten()
            ->contains(fn (mixed $item): bool => is_string($item) && str_contains($item, '{'));
    }
}
