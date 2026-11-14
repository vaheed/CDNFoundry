<?php

namespace App\Console\Commands;

use App\Models\UsageRollup;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps usage_rollups small for years: hourly rows become one row per day after the hourly window, and daily rows become
 * one row per month after the daily window. Totals never change, a compacted row is final, and every step is one
 * transaction, so a stopped run leaves either the detail rows or their sum, never both.
 */
class CompactUsageRollups extends Command
{
    protected $signature = 'cdnf:usage:compact {--periods=40 : The most days and months to compact in one run}';

    protected $description = 'Sum old hourly usage rows into daily rows, and old daily rows into monthly rows';

    private const DOMAINS_PER_TRANSACTION = 500;

    public function handle(PlatformSettings $settings): int
    {
        $limit = max(1, min(400, (int) $this->option('periods')));
        $now = CarbonImmutable::now('UTC');
        $days = $this->compact('hour', 'day', $now->subDays($settings->integer('operations', 'usage_hourly_retention_days'))->startOfDay(), $limit);
        $months = $this->compact('day', 'month', $now->subDays($settings->integer('operations', 'usage_daily_retention_days'))->startOfMonth(), $limit);
        $this->info("Compacted {$days} days and {$months} months of usage.");

        return self::SUCCESS;
    }

    /** @return int how many periods were compacted */
    private function compact(string $from, string $to, CarbonImmutable $cutoff, int $limit): int
    {
        $done = 0;
        while ($done < $limit) {
            $oldest = UsageRollup::query()->where('granularity', $from)->where('interval_start', '<', $cutoff)->min('interval_start');
            if ($oldest === null) {
                break;
            }
            $start = $to === 'day' ? CarbonImmutable::parse($oldest, 'UTC')->startOfDay() : CarbonImmutable::parse($oldest, 'UTC')->startOfMonth();
            $end = $to === 'day' ? $start->addDay() : $start->addMonth();
            if ($end > $cutoff) {
                break;
            }
            // A month is summed from days only after every hour of it became a day.
            if ($to === 'month' && UsageRollup::query()->where('granularity', 'hour')->where('interval_start', '>=', $start)->where('interval_start', '<', $end)->exists()) {
                break;
            }
            if (! $this->compactPeriod($from, $to, $start, $end)) {
                // Provisional rows would make this period come up again on every run; wait until they are finalized.
                $this->warn("Usage between {$start->toIso8601String()} and {$end->toIso8601String()} is not finalized yet.");
                break;
            }
            $done++;
        }

        return $done;
    }

    /** @return bool false when a row of the period is not finalized, so the period stays as it is for those domains */
    private function compactPeriod(string $from, string $to, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $detail = fn () => UsageRollup::query()->where('granularity', $from)->where('interval_start', '>=', $start)->where('interval_start', '<', $end);
        $ready = $detail()->selectRaw('domain_id, sum(requests) as requests, sum(bytes_in) as bytes_in, sum(bytes_out) as bytes_out, sum(cache_hits) as cache_hits, sum(dns_queries) as dns_queries, sum(case when status = \'finalized\' then 0 else 1 end) as open_rows')
            ->groupBy('domain_id')->orderBy('domain_id')->get();
        // A domain whose period still holds a provisional row is left alone until it is finalized.
        $rows = $ready->where('open_rows', '=', 0)->values();
        foreach ($rows->chunk(self::DOMAINS_PER_TRANSACTION) as $chunk) {
            DB::transaction(function () use ($chunk, $detail, $to, $start, $end): void {
                foreach ($chunk as $row) {
                    UsageRollup::query()->updateOrCreate(
                        ['domain_id' => $row->domain_id, 'interval_start' => $start, 'granularity' => $to],
                        ['interval_end' => $end, 'requests' => $row->requests, 'bytes_in' => $row->bytes_in, 'bytes_out' => $row->bytes_out, 'cache_hits' => $row->cache_hits,
                            'dns_queries' => $row->dns_queries, 'status' => 'finalized', 'source_finalized_at' => now()],
                    );
                }
                $detail()->whereIn('domain_id', $chunk->pluck('domain_id'))->delete();
            });
        }

        return $ready->count() === $rows->count();
    }
}
