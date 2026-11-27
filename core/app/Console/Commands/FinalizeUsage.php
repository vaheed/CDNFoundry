<?php

namespace App\Console\Commands;

use App\Jobs\BuildUsageRollups;
use App\Models\UsageCoverage;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class FinalizeUsage extends Command
{
    protected $signature = 'cdnf:usage:finalize';

    protected $description = 'Build every missing usage hour up to the most recently ended one, and retry recent incomplete hours';

    /** Hours one run may queue, so a long outage is caught up over several runs and never starves other work. */
    private const HOURS_PER_RUN = 48;

    /** An incomplete hour is retried while late telemetry can still arrive; after this it stays incomplete. */
    private const INCOMPLETE_RETRY_HOURS = 24;

    public function handle(PlatformSettings $settings): int
    {
        $last = CarbonImmutable::now('UTC')->subHour()->startOfHour()->subHour();
        $first = UsageCoverage::query()->min('interval_start');
        // A fleet with no coverage yet starts at the latest hour: rows finalized before coverage existed are never recomputed.
        $start = $first === null ? $last : CarbonImmutable::parse($first, 'UTC');
        $floor = $last->subDays($settings->integer('operations', 'usage_hourly_retention_days'))->startOfDay();
        $start = $start < $floor ? $floor : $start;
        $built = UsageCoverage::query()->where('interval_start', '>=', $start)->where('interval_start', '<=', $last)->get(['interval_start', 'status'])
            ->mapWithKeys(fn (UsageCoverage $row): array => [$row->interval_start->utc()->toIso8601String() => $row->status]);
        $retryFrom = $last->subHours(self::INCOMPLETE_RETRY_HOURS);
        $queued = 0;
        $runStart = null;
        $previous = null;
        $flush = function () use (&$runStart, &$previous, &$queued): void {
            if ($runStart !== null) {
                BuildUsageRollups::dispatch($runStart->toIso8601String(), $previous->addHour()->toIso8601String());
                $queued++;
            }
            $runStart = null;
        };
        $hours = 0;
        for ($hour = $start; $hour <= $last && $hours < self::HOURS_PER_RUN; $hour = $hour->addHour()) {
            $status = $built->get($hour->toIso8601String());
            $needed = $status === null || ($status === 'incomplete' && $hour >= $retryFrom);
            if ($needed) {
                $runStart ??= $hour;
                $previous = $hour;
                $hours++;
            } else {
                $flush();
            }
        }
        $flush();
        $this->info("Queued {$queued} usage build(s) covering {$hours} hour(s) up to {$last->addHour()->toIso8601String()}.");

        return self::SUCCESS;
    }
}
