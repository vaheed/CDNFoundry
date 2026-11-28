<?php

namespace App\Support;

use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * The platform policy for active origin monitoring (Platform settings, Active origin monitoring). The platform switch overrides
 * every domain and origin setting. It never touches passive failure detection or runtime failover.
 */
final class OriginMonitoring
{
    /** A manual test is one probe from one edge, at most one for an origin every this many seconds. */
    public const MANUAL_SPACING_SECONDS = 30;

    /** The most probes one destination (address and port) receives in a minute, confirmations included. Not configurable. */
    public const DESTINATION_PROBES_PER_MINUTE = 8;

    /** The destination an origin is probed at. Origins that share it share one budget, however many domains or records point at it. */
    public static function destination(array $origin): string
    {
        return strtolower((string) ($origin['host'] ?? '')).':'.(int) ($origin['port'] ?? 0);
    }

    /**
     * How many of `$wanted` probes may start now for the destination and the domain. Probes already queued or answered in the last
     * minute count, so a burst of records, hostnames or domains adds no probe capacity.
     */
    public static function allowance(string $destination, int $domainId, int $wanted): int
    {
        $since = now()->subMinute();
        $recent = EdgeTask::query()->where('type', 'origin_test')->where('created_at', '>=', $since);
        $perDestination = (clone $recent)->where('payload->destination', $destination)->count();
        $perDomain = (clone $recent)->where('payload->domain_id', $domainId)->count();
        $domainLimit = app(PlatformSettings::class)->integer('origin_monitoring', 'domain_probes_per_minute');
        // An account owns the domains its user is assigned to. Creating more domains adds no probe capacity for it.
        $owners = Domain::query()->find($domainId)?->users()->pluck('users.id');
        $ownerDomains = $owners === null || $owners->isEmpty() ? collect() : Domain::query()->whereHas('users', fn ($query) => $query->whereIn('users.id', $owners))->pluck('id');
        $perAccount = $ownerDomains->isEmpty() ? 0 : (clone $recent)->whereIn('payload->domain_id', $ownerDomains->all())->count();
        $accountLimit = app(PlatformSettings::class)->integer('origin_monitoring', 'account_probes_per_minute');

        return max(0, min($wanted, self::DESTINATION_PROBES_PER_MINUTE - $perDestination, $domainLimit - $perDomain, $accountLimit - $perAccount));
    }

    /** What one probe is charged to a worker, in slot-seconds: a dead origin holds a slot for the whole timeout, a healthy one briefly. */
    public const DEAD_PROBE_COST = 5.0;

    public const HEALTHY_PROBE_COST = 0.5;

    /**
     * The slot-seconds a worker has been charged in the last minute, from the probes it was given. A probe is charged its measured
     * duration once answered, and the dead-origin cost while it is still waiting or when it failed (the worst case).
     */
    public static function workerLoad(string $edgeId): float
    {
        $load = 0.0;
        foreach (EdgeTask::query()->where('type', 'origin_test')->where('edge_id', $edgeId)->where('created_at', '>=', now()->subMinute())->get(['status', 'result']) as $task) {
            $healthy = $task->status === 'succeeded' && ($task->result['status'] ?? null) === 'healthy';
            $load += $healthy ? max(self::HEALTHY_PROBE_COST, min(self::DEAD_PROBE_COST, ((float) ($task->result['latency_ms'] ?? 0)) / 1000)) : self::DEAD_PROBE_COST;
        }

        return $load;
    }

    /** Whether a worker may take one more probe now: its charged load plus the worst case stays inside the per-minute worker budget. */
    public static function workerHasCapacity(string $edgeId): bool
    {
        return self::workerLoad($edgeId) + self::DEAD_PROBE_COST <= app(PlatformSettings::class)->integer('origin_monitoring', 'worker_slot_seconds_per_minute');
    }

    /**
     * Probes that would test the same thing are one probe: same address and port, host header, SNI, scheme, TLS policy and health
     * path, from the same reachability groups. The fingerprint holds no domain, record or account.
     *
     * @param  array<string, mixed>  $origin
     * @param  list<array{group: string, edge_id: ?string}>  $groups
     */
    public static function fingerprint(array $origin, array $groups): string
    {
        $names = collect($groups)->pluck('group')->sort()->values()->all();

        return hash('sha256', json_encode([self::destination($origin), $origin['scheme'] ?? '', $origin['host_header'] ?? '', $origin['sni'] ?? '', (bool) ($origin['verify_tls'] ?? false),
            $origin['health_check']['path'] ?? '', $names], JSON_THROW_ON_ERROR));
    }

    /**
     * The newest active signal of another record that probed the same thing from the same groups inside the platform minimum interval,
     * or null. Its facts (reachable or not, from where, how fast) are copied to each domain that asks; nothing else crosses.
     *
     * @return array<mixed, mixed>|null
     */
    public static function sharedSignal(string $fingerprint, int $recordId, string $role): ?array
    {
        $fresh = now()->subSeconds(app(PlatformSettings::class)->integer('origin_monitoring', 'minimum_interval_seconds'))->timestamp;

        $peers = DnsRecord::query()->where('id', '!=', $recordId)->where('mode', 'proxied')->where("origin_signals->{$role}->active->fingerprint", $fingerprint)
            ->orderByDesc('updated_at')->limit(5)->get();
        foreach ($peers as $peer) {
            $signal = data_get($peer->getAttribute('origin_signals'), "{$role}.active");
            if (is_array($signal) && (int) strtotime((string) ($signal['at'] ?? '')) >= $fresh) {
                return $signal;
            }
        }

        return null;
    }

    /** A probe task is useless after this: a scheduled one after one minimum interval, a manual one after a minute. The agent drops it then. */
    public static function taskExpiry(bool $scheduled): int
    {
        return now()->addSeconds($scheduled ? app(PlatformSettings::class)->integer('origin_monitoring', 'minimum_interval_seconds') : 60)->timestamp;
    }

    public static function enabled(): bool
    {
        return (bool) app(PlatformSettings::class)->get('origin_monitoring', 'enabled');
    }

    /** The scheduled interval of one origin: what it asks for, never below the platform floor. */
    public static function interval(int $requested): int
    {
        return max($requested, app(PlatformSettings::class)->integer('origin_monitoring', 'minimum_interval_seconds'));
    }

    /** A failing origin is checked less often, never by more than this many times its interval, so a dead origin costs the budget less. */
    public const MAXIMUM_STRETCH = 5;

    /** The interval of an origin whose last `$streak` scheduled checks failed. A healthy origin (streak 0) keeps its interval. */
    public static function stretched(int $interval, int $streak): int
    {
        return $interval * min(self::MAXIMUM_STRETCH, 1 + max(0, $streak));
    }

    /** Whether a manual test may start now. */
    public static function manualAllowed(): bool
    {
        return self::enabled() || (bool) app(PlatformSettings::class)->get('origin_monitoring', 'manual_tests_when_off');
    }

    /**
     * Stops every origin check that has not finished: the operations become failed with the reason, and the tasks no edge has
     * answered yet fail so no edge probes. A task already running on an edge finishes and its result is dropped by the operation.
     *
     * @return int the number of operations stopped
     */
    public static function cancelPending(): int
    {
        return DB::transaction(function (): int {
            $operations = Operation::query()->where('type', 'edge.origin_test')->whereIn('status', ['pending', 'running'])->lockForUpdate()->get();
            foreach ($operations as $operation) {
                EdgeTask::query()->where('type', 'origin_test')->where('status', 'pending')->where('payload->operation_id', $operation->id)
                    ->update(['status' => 'failed', 'result' => ['cancelled' => true, 'reason' => 'Active origin monitoring is off.']]);
                $operation->update(['status' => 'failed', 'error' => 'Active origin monitoring is off.', 'finished_at' => now()]);
            }

            return $operations->count();
        });
    }
}
