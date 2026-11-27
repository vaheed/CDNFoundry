<?php

namespace App\Support;

use App\Models\DnsRecord;
use Carbon\CarbonInterface;

/**
 * The health of one proxied record, kept as separate signals per origin role: the scheduled active check, the
 * manual test and the passive failures reported from traffic. A signal never overwrites another kind. It carries
 * the checksum of the origin configuration it was measured against, so an edited origin turns old evidence stale
 * instead of silently reusing it, and evidence expires by age. The runtime failover decision never reads this.
 */
final class OriginHealth
{
    public const STATES = ['disabled', 'pending', 'healthy', 'unhealthy', 'degraded', 'unknown', 'stale'];

    public const ROLES = ['primary', 'backup'];

    public const KINDS = ['active', 'manual', 'passive'];

    /** A manual test and a passive failure report describe the moment they were made. */
    public const MANUAL_TTL_SECONDS = 86400;

    public const PASSIVE_TTL_SECONDS = 900;

    private const FIELDS = ['status', 'observer', 'at', 'checksum', 'latency_ms', 'http_status', 'failure_reason', 'resolved_address', 'tls_result', 'failure_count', 'scope', 'failed_groups', 'not_monitored', 'worker_faults'];

    public static function checksum(array $origin): string
    {
        return hash('sha256', ArtifactSigner::encode($origin));
    }

    /** @return array<string, mixed>|null the origin of a role, or null when the record has none */
    public static function originFor(DnsRecord $record, string $role): ?array
    {
        $origin = $role === 'backup' ? ($record->origin['backup'] ?? null) : $record->origin;

        return is_array($origin) ? $origin : null;
    }

    /** Store one signal under the record lock the caller already holds. Only the typed fields are kept. */
    public static function record(DnsRecord $record, string $role, string $kind, array $signal): void
    {
        $origin = self::originFor($record, $role);
        if ($origin === null || ! in_array($role, self::ROLES, true) || ! in_array($kind, self::KINDS, true)
            || ! in_array($signal['status'] ?? null, ['healthy', 'unhealthy'], true)) {
            return;
        }
        $signal = array_filter(array_intersect_key($signal, array_flip(self::FIELDS)), fn ($value): bool => $value !== null && (is_scalar($value)));
        $signal['checksum'] = self::checksum($origin);
        $signal['at'] ??= now()->toIso8601String();
        $signals = $record->origin_signals ?? [];
        $signals[$role][$kind] = $signal;
        $record->update(['origin_signals' => $signals]);
    }

    /**
     * Keep the evidence an origin edit does not invalidate: drop roles the origin no longer has, and the active
     * result when scheduled monitoring was switched off. Passive and manual evidence stay and turn stale by checksum.
     *
     * @return array<string, mixed>|null
     */
    public static function afterOriginChange(DnsRecord $record): ?array
    {
        $signals = $record->origin_signals;
        if (! is_array($signals)) {
            return null;
        }
        foreach (self::ROLES as $role) {
            if (self::originFor($record, $role) === null) {
                unset($signals[$role]);
            }
        }
        if (! (bool) data_get($record->origin, 'health_check.enabled', false)) {
            unset($signals['primary']['active']);
        }
        $signals = array_filter($signals, fn ($kinds): bool => is_array($kinds) && $kinds !== []);

        return $signals === [] ? null : $signals;
    }

    /**
     * @return array{status: string, state: string, roles: array<string, array<string, mixed>>}
     */
    public static function summarize(DnsRecord $record, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $roles = [];
        foreach (self::ROLES as $role) {
            $origin = self::originFor($record, $role);
            if ($origin !== null) {
                $roles[$role] = self::summarizeRole($record, $role, $origin, $now);
            }
        }
        $state = $roles['primary']['state'] ?? 'unknown';

        return ['status' => $state, 'state' => $state, 'roles' => $roles];
    }

    /** @return array<string, mixed> */
    private static function summarizeRole(DnsRecord $record, string $role, array $origin, CarbonInterface $now): array
    {
        // The platform switch overrides the origin's own setting: with monitoring off the active signal is disabled.
        $monitoring = $role === 'primary' && OriginMonitoring::enabled() && (bool) ($origin['health_check']['enabled'] ?? false);
        $interval = max(60, (int) ($origin['health_check']['interval_seconds'] ?? 300));
        $checksum = self::checksum($origin);
        $signals = $record->origin_signals[$role] ?? self::legacySignals($record, $role);
        $detail = [];
        $fresh = [];
        foreach (self::KINDS as $kind) {
            $signal = $signals[$kind] ?? null;
            // With monitoring off an earlier active result is not evidence of anything current: it reads as disabled.
            if ($kind === 'active' && ! $monitoring) {
                $detail[$kind] = ['state' => 'disabled'];

                continue;
            }
            if (! is_array($signal)) {
                $detail[$kind] = ['state' => 'none'];

                continue;
            }
            $age = $now->getTimestamp() - (int) strtotime((string) ($signal['at'] ?? ''));
            $ttl = match ($kind) {
                'active' => max(3 * $interval, 900),
                'manual' => self::MANUAL_TTL_SECONDS,
                'passive' => self::PASSIVE_TTL_SECONDS,
            };
            $isFresh = (! isset($signal['checksum']) || hash_equals($checksum, (string) $signal['checksum'])) && $age >= -60 && $age <= $ttl;
            // A failure seen from some probe groups only, or not confirmed by a second worker, is degraded, never an origin failure.
            $reading = $kind === 'active' && in_array($signal['scope'] ?? '', ['regional', 'unconfirmed'], true) ? 'degraded' : (string) $signal['status'];
            $detail[$kind] = ['state' => $isFresh ? $reading : 'stale', 'at' => $signal['at'] ?? null]
                + array_intersect_key($signal, array_flip(['observer', 'latency_ms', 'http_status', 'failure_reason', 'resolved_address', 'failure_count', 'scope', 'failed_groups', 'not_monitored', 'worker_faults']));
            if ($isFresh) {
                $fresh[] = $reading;
            }
        }
        $any = array_filter($monitoring ? $signals : array_diff_key($signals, ['active' => 1]), 'is_array') !== [];
        $createdAge = $now->getTimestamp() - (int) $record->created_at?->getTimestamp();
        $state = match (true) {
            $fresh !== [] => count(array_unique($fresh)) > 1 ? 'degraded' : $fresh[0],
            $any => 'stale',
            $monitoring => $createdAge < max(3 * $interval, 600) ? 'pending' : 'unknown',
            default => 'disabled',
        };

        return ['state' => $state, 'monitoring' => $monitoring ? 'enabled' : 'disabled', 'signals' => $detail];
    }

    /**
     * Until a record has new-style signals, read the single legacy value: a passive report is passive, anything
     * else is a manual or scheduled result. It has no checksum, so only its age can make it stale.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function legacySignals(DnsRecord $record, string $role): array
    {
        $legacy = $record->origin_health;
        if (! is_array($legacy) || ! in_array($legacy['status'] ?? null, ['healthy', 'unhealthy'], true)
            || ($legacy['origin_role'] ?? 'primary') !== $role) {
            return [];
        }
        $legacy['at'] = $legacy['reported_at'] ?? null;
        $legacy['observer'] = $legacy['edge_id'] ?? null;

        return [($legacy['source'] ?? null) === 'passive' ? 'passive' : 'manual' => array_intersect_key($legacy, array_flip(self::FIELDS))];
    }
}
