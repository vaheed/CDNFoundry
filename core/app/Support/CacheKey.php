<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Validation\ValidationException;

final class CacheKey
{
    public static function fromUrl(Domain $domain, string $url, string $queryPolicy = 'include_all', array $parameters = []): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            throw ValidationException::withMessages(['urls' => 'Every purge URL must be an absolute HTTP or HTTPS URL without credentials or a fragment.']);
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $domainName = strtolower($domain->name);
        if (! in_array($scheme, ['http', 'https'], true) || ($host !== $domainName && ! str_ends_with($host, '.'.$domainName))) {
            throw ValidationException::withMessages(['urls' => 'Every purge URL must use HTTP or HTTPS and belong to the selected domain.']);
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            throw ValidationException::withMessages(['urls' => 'Purge URLs may use only the default HTTP or HTTPS port.']);
        }
        $path = $parts['path'] ?? '/';
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        $selected = array_fill_keys($parameters, true);
        $pairs = [];
        foreach (explode('&', $parts['query'] ?? '') as $rawPair) {
            if ($rawPair === '') {
                continue;
            }
            [$rawName, $rawValue] = array_pad(explode('=', $rawPair, 2), 2, '');
            $name = rawurldecode($rawName);
            $include = $queryPolicy === 'include_all'
                || ($queryPolicy === 'include_selected' && isset($selected[$name]))
                || ($queryPolicy === 'ignore_selected' && ! isset($selected[$name]));
            if ($include) {
                $pairs[] = [$name, rawurldecode($rawValue)];
            }
        }
        usort($pairs, fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);
        $encoded = implode('&', array_map(fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs));
        $query = $encoded === '' ? '' : '?'.$encoded;

        return $scheme.'|'.$host.'|'.$path.$query;
    }
}
