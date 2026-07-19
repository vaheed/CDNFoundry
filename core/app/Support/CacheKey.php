<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Validation\ValidationException;

final class CacheKey
{
    public static function fromUrl(Domain $domain, string $url, bool $includeQuery): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            throw ValidationException::withMessages(['urls' => 'Every purge URL must be an absolute HTTP or HTTPS URL without credentials or a fragment.']);
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        if (! in_array($scheme, ['http', 'https'], true) || $host !== strtolower($domain->name)) {
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
        $query = $includeQuery && isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'|'.$host.'|'.$path.$query;
    }
}
