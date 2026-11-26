<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Validation\ValidationException;

/**
 * The incident profile: one temporary, time-limited bundle of protections for one domain. It is desired state with an expiry and
 * travels to the cells as part of the security overlay, so turning it on and off each cost one overlay sequence and no configuration revision.
 */
final class IncidentProfile
{
    public const MINIMUM_MINUTES = 5;

    public const MAXIMUM_MINUTES = 1440;

    public const DEFAULT_MINUTES = 60;

    /** @var list<string> methods a domain under attack still accepts */
    public const METHODS = ['GET', 'HEAD', 'OPTIONS', 'POST'];

    public const MAXIMUM_BODY_BYTES = 1048576;

    /** Methods a domain may let through without the challenge, for example OPTIONS so a browser's preflight request is answered. */
    public const BYPASS_METHODS = ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public const MAXIMUM_BYPASS_PREFIXES = 8;

    /** Paths a visitor reaches without the challenge, so API clients and certificate validation keep working. */
    public const CHALLENGE_BYPASS_PREFIXES = ['/api/', '/.well-known/'];

    /**
     * What the profile turns on. Every protection is enforced by the cell runtime from the overlay and ends by the cell's own clock.
     *
     * @return list<array{key: string, label: string, enforced: bool}>
     */
    public static function protections(): array
    {
        return [
            ['key' => 'limits', 'label' => 'Protected request and connection limits', 'enforced' => true],
            ['key' => 'waf', 'label' => 'Web application firewall in blocking mode, high sensitivity', 'enforced' => true],
            ['key' => 'methods', 'label' => 'Only GET, HEAD, OPTIONS, and POST, with bodies up to 1 MiB', 'enforced' => true],
            ['key' => 'challenge', 'label' => 'Browser challenge before a visitor reaches the origin', 'enforced' => true],
        ];
    }

    /**
     * Validates what a domain chooses to let through without the challenge. `null` keeps the platform default; an empty list lets
     * nothing through. Prefixes are absolute paths, so a value that is not one is refused instead of matching nothing.
     *
     * @return array{prefixes: ?list<string>, methods: ?list<string>}
     */
    public static function validatedBypass(?array $prefixes, ?array $methods): array
    {
        $errors = [];
        if ($prefixes !== null) {
            $prefixes = array_values(array_unique(array_map(fn ($prefix): string => is_string($prefix) ? trim($prefix) : '', $prefixes)));
            if (count($prefixes) > self::MAXIMUM_BYPASS_PREFIXES) {
                $errors['bypass_prefixes'] = 'At most '.self::MAXIMUM_BYPASS_PREFIXES.' prefixes can skip the challenge.';
            }
            foreach ($prefixes as $prefix) {
                if ($prefix === '' || ! str_starts_with($prefix, '/') || strlen($prefix) > 128 || preg_match('/[\x00-\x20\x7f?#\\\\]/', $prefix) === 1) {
                    $errors['bypass_prefixes'] = 'A prefix starts with / and has no spaces, query string, or fragment (for example /api/).';
                }
            }
        }
        if ($methods !== null) {
            $methods = array_values(array_unique(array_map(fn ($method): string => is_string($method) ? strtoupper(trim($method)) : '', $methods)));
            if (array_diff($methods, self::BYPASS_METHODS) !== []) {
                $errors['bypass_methods'] = 'Methods can be '.implode(', ', self::BYPASS_METHODS).'.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['prefixes' => $prefixes, 'methods' => $methods];
    }

    public static function active(Domain $domain): bool
    {
        return $domain->incident_expires_at !== null && $domain->incident_expires_at->isFuture();
    }

    /** @return array{expires_at: int, waf: array{mode: string, sensitivity: string}, methods: list<string>, max_body_bytes: int, challenge: bool, challenge_bypass_prefixes: list<string>, challenge_bypass_methods: list<string>}|null */
    public static function payload(Domain $domain): ?array
    {
        if (! self::active($domain)) {
            return null;
        }

        return [
            'expires_at' => $domain->incident_expires_at->getTimestamp(),
            'waf' => ['mode' => 'block', 'sensitivity' => 'high'],
            'methods' => self::METHODS, 'max_body_bytes' => self::MAXIMUM_BODY_BYTES, 'challenge' => true,
            'challenge_bypass_prefixes' => $domain->incident_bypass_prefixes ?? self::CHALLENGE_BYPASS_PREFIXES,
            'challenge_bypass_methods' => $domain->incident_bypass_methods ?? [],
        ];
    }
}
