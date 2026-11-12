<?php

namespace App\Support;

use App\Models\Domain;

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

    /**
     * What the profile turns on. `enforced` says whether the cell runtime enforces it today: request limits are enforced through the
     * existing overlay; the others are delivered in the overlay and take effect once the cell runtime supports them.
     *
     * @return list<array{key: string, label: string, enforced: bool}>
     */
    public static function protections(): array
    {
        return [
            ['key' => 'limits', 'label' => 'Protected request and connection limits', 'enforced' => true],
            ['key' => 'waf', 'label' => 'Web application firewall in blocking mode, high sensitivity', 'enforced' => false],
            ['key' => 'methods', 'label' => 'Only GET, HEAD, OPTIONS, and POST, with bodies up to 1 MiB', 'enforced' => false],
            ['key' => 'challenge', 'label' => 'Browser challenge before a visitor reaches the origin', 'enforced' => false],
        ];
    }

    public static function active(Domain $domain): bool
    {
        return $domain->incident_expires_at !== null && $domain->incident_expires_at->isFuture();
    }

    /** @return array{expires_at: int, waf: array{mode: string, sensitivity: string}, methods: list<string>, max_body_bytes: int, challenge: bool}|null */
    public static function payload(Domain $domain): ?array
    {
        if (! self::active($domain)) {
            return null;
        }

        return [
            'expires_at' => $domain->incident_expires_at->getTimestamp(),
            'waf' => ['mode' => 'block', 'sensitivity' => 'high'],
            'methods' => self::METHODS, 'max_body_bytes' => self::MAXIMUM_BODY_BYTES, 'challenge' => true,
        ];
    }
}
