<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The canonical cache-key target. The Lua cell runtime (docker/openresty/cache_key.lua)
 * implements the same rules; both are tested against tests/fixtures/cache_key_vectors.json.
 * The path stays percent-encoded, so %2F and %3F never merge with "/" and "?".
 */
final class CacheKey
{
    public const MAXIMUM_QUERY_ARGUMENTS = 128;

    public static function fromUrl(Domain $domain, string $url, string $queryPolicy = 'include_all', array $parameters = []): string
    {
        if (str_contains($url, '#') || ! preg_match('~^(https?)://([^/?#]*)(.*)$~is', $url, $match)) {
            throw ValidationException::withMessages(['urls' => 'Every purge URL must be an absolute HTTP or HTTPS URL without credentials or a fragment.']);
        }
        $scheme = strtolower($match[1]);
        $authority = $match[2];
        if (str_contains($authority, '@')) {
            throw ValidationException::withMessages(['urls' => 'Every purge URL must be an absolute HTTP or HTTPS URL without credentials or a fragment.']);
        }
        if (! preg_match('~^([^:\[\]]+)(?::(\d+))?$~', $authority, $hostParts)) {
            throw ValidationException::withMessages(['urls' => 'Every purge URL must use HTTP or HTTPS and belong to the selected domain.']);
        }
        $host = strtolower(rtrim($hostParts[1], '.'));
        $domainName = strtolower($domain->name);
        if ($host !== $domainName && ! str_ends_with($host, '.'.$domainName)) {
            throw ValidationException::withMessages(['urls' => 'Every purge URL must use HTTP or HTTPS and belong to the selected domain.']);
        }
        $port = isset($hostParts[2]) ? (int) $hostParts[2] : null;
        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            throw ValidationException::withMessages(['urls' => 'Purge URLs may use only the default HTTP or HTTPS port.']);
        }
        $target = $match[3] === '' ? '/' : $match[3];
        if ($target[0] === '?') {
            $target = '/'.$target;
        }
        try {
            $canonical = self::target($target, $queryPolicy, $parameters);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['urls' => 'This purge URL cannot be cached or purged: '.$exception->getMessage().'.']);
        }

        return $scheme.'|'.$host.'|'.$canonical;
    }

    /**
     * Normalize a raw request target (path and optional query) to the canonical key target.
     *
     * @throws InvalidArgumentException with the reason as its message when the target has no key
     */
    public static function target(string $rawTarget, string $queryPolicy = 'include_all', array $parameters = []): string
    {
        $question = strpos($rawTarget, '?');
        $path = $question === false ? $rawTarget : substr($rawTarget, 0, $question);
        $rawQuery = $question === false ? '' : substr($rawTarget, $question + 1);
        $normalized = self::normalizePath($path);

        $selected = array_fill_keys($parameters, true);
        $pairs = [];
        $count = 0;
        foreach (explode('&', $rawQuery) as $rawPair) {
            if ($rawPair === '') {
                continue;
            }
            if (++$count > self::MAXIMUM_QUERY_ARGUMENTS) {
                throw new InvalidArgumentException('too_many_query_arguments');
            }
            $equals = strpos($rawPair, '=');
            $name = self::decodeQueryComponent($equals === false ? $rawPair : substr($rawPair, 0, $equals));
            $value = self::decodeQueryComponent($equals === false ? '' : substr($rawPair, $equals + 1));
            $include = $queryPolicy === 'include_all'
                || ($queryPolicy === 'include_selected' && isset($selected[$name]))
                || ($queryPolicy === 'ignore_selected' && ! isset($selected[$name]));
            if ($include) {
                $pairs[] = [$name, $value];
            }
        }
        // usort is stable since PHP 8.0, so repeated names keep the order the client sent.
        usort($pairs, fn (array $left, array $right): int => strcmp($left[0], $right[0]));
        if ($pairs === []) {
            return $normalized;
        }

        return $normalized.'?'.implode('&', array_map(fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs));
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('not_origin_form');
        }
        $out = '';
        $length = strlen($path);
        for ($index = 0; $index < $length; $index++) {
            $character = $path[$index];
            if ($character === '%') {
                $hex = substr($path, $index + 1, 2);
                if (strlen($hex) !== 2 || ! ctype_xdigit($hex)) {
                    throw new InvalidArgumentException('invalid_percent_encoding');
                }
                $decoded = chr(hexdec($hex));
                $out .= preg_match('/^[A-Za-z0-9\-._~]$/', $decoded) ? $decoded : '%'.strtoupper($hex);
                $index += 2;
            } elseif (preg_match('/^[A-Za-z0-9\-._~!$&\'()*+,;=:@\/]$/', $character)) {
                $out .= $character;
            } else {
                throw new InvalidArgumentException('invalid_path_character');
            }
        }
        if (str_starts_with($out, '//')) {
            throw new InvalidArgumentException('empty_segment');
        }
        $segments = explode('/', substr($out, 1));
        foreach ($segments as $number => $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('dot_segment');
            }
            if ($segment === '' && $number < count($segments) - 1) {
                throw new InvalidArgumentException('empty_segment');
            }
        }

        return $out;
    }

    private static function decodeQueryComponent(string $raw): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw)) {
            throw new InvalidArgumentException('invalid_percent_encoding');
        }

        return rawurldecode(str_replace('+', ' ', $raw));
    }
}
