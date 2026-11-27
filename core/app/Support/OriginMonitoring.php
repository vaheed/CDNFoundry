<?php

namespace App\Support;

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

        return max(0, min($wanted, self::DESTINATION_PROBES_PER_MINUTE - $perDestination, $domainLimit - $perDomain));
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
