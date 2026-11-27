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
