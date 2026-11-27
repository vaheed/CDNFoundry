<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\Edge;
use App\Models\Operation;
use App\Models\UsageCoverage;
use App\Models\UsageRollup;
use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Builds hourly usage from the analytics store. Rows are sparse: a domain with no traffic in an hour has no row, and
 * the hour's coverage record says whether that absence is a known zero. A fleet that had edges but produced no
 * telemetry at all for an hour is `incomplete`, never a finalized zero. A finalized row is not silently rewritten: a
 * rebuild that changes its values marks it `corrected` and keeps the earlier values with the reason.
 */
class BuildUsageRollups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MEASURES = ['requests', 'bytes_in', 'bytes_out', 'cache_hits', 'dns_queries'];

    /** The newest corrections a row keeps, so a flapping source cannot grow one row without bound. */
    public const MAXIMUM_CORRECTIONS = 5;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(public string $from, public string $to, public ?int $domainId = null, public ?string $operationId = null)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function handle(AnalyticsStore $store): void
    {
        $operation = $this->operationId ? Operation::query()->find($this->operationId) : null;
        $operation?->update(['status' => 'running', 'started_at' => now()]);
        try {
            $from = CarbonImmutable::parse($this->from)->utc();
            $to = CarbonImmutable::parse($this->to)->utc();
            for ($interval = $from; $interval < $to; $interval = $interval->addHour()) {
                $this->buildHour($store, $interval);
            }
            $operation?->update(['status' => 'succeeded', 'finished_at' => now(), 'result' => ['from' => $this->from, 'to' => $this->to]]);
        } catch (Throwable $exception) {
            // Nothing is recorded for the hour that failed, so the catch-up build finds it unbuilt and tries again.
            $operation?->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'analytics_unavailable: Usage source is unavailable.']);
            throw $exception;
        }
    }

    private function buildHour(AnalyticsStore $store, CarbonImmutable $interval): void
    {
        $end = $interval->addHour();
        $withTraffic = 0;
        Domain::query()->when($this->domainId !== null, fn ($query) => $query->whereKey($this->domainId))
            ->orderBy('id')->select(['id', 'name'])->chunkById(250, function ($domains) use ($store, $interval, $end, &$withTraffic): void {
                $scope = $domains->pluck('name', 'id')->all();
                $totals = collect($store->usageInterval($interval, $end, $scope))->groupBy('domain_id')->map(fn ($rows): array => array_combine(
                    self::MEASURES, array_map(fn (string $measure): int => (int) $rows->sum($measure), self::MEASURES),
                ));
                // Usage summed into a day or a month is final: the hourly detail is gone, so it is never rebuilt.
                $compacted = UsageRollup::query()->whereIn('domain_id', array_keys($scope))->whereIn('granularity', ['day', 'month'])
                    ->where('interval_start', '<=', $interval)->where('interval_end', '>=', $end)->pluck('domain_id')->all();
                foreach (array_diff(array_keys($scope), $compacted) as $domainId) {
                    $values = $totals->get((string) $domainId, $totals->get($domainId));
                    if ($values === null || array_sum($values) === 0) {
                        continue;
                    }
                    $withTraffic++;
                    $this->write($domainId, $scope[$domainId], $interval, $end, $values);
                }
            });
        // A partial rebuild of one domain says nothing about the rest of the fleet.
        if ($this->domainId === null) {
            $this->recordCoverage($interval, $end, $withTraffic);
        }
    }

    /** @param array<string, int> $values */
    private function write(int $domainId, string $name, CarbonImmutable $interval, CarbonImmutable $end, array $values): void
    {
        $row = UsageRollup::query()->where(['domain_id' => $domainId, 'interval_start' => $interval, 'granularity' => 'hour'])->first();
        if ($row === null) {
            UsageRollup::query()->create([
                'domain_id' => $domainId, 'interval_start' => $interval, 'interval_end' => $end, 'granularity' => 'hour', ...$values,
                'status' => 'finalized', 'source_finalized_at' => now(), 'domain_name' => $name,
                'owner_user_ids' => Domain::query()->find($domainId)?->users()->orderBy('users.id')->limit(20)->pluck('users.id')->map(fn ($id): int => (int) $id)->all() ?? [],
            ]);

            return;
        }
        $previous = array_combine(self::MEASURES, array_map(fn (string $measure): int => (int) $row->{$measure}, self::MEASURES));
        if ($previous === $values) {
            return;
        }
        $corrections = $row->corrections ?? [];
        $corrections[] = ['at' => now()->toIso8601String(), 'reason' => 'late_telemetry', 'previous' => $previous];
        $row->update([...$values, 'status' => 'corrected', 'source_finalized_at' => now(), 'corrections' => array_slice($corrections, -self::MAXIMUM_CORRECTIONS)]);
    }

    private function recordCoverage(CarbonImmutable $interval, CarbonImmutable $end, int $withTraffic): void
    {
        $silent = $withTraffic === 0 && Edge::query()->where('enabled', true)->exists();
        UsageCoverage::query()->updateOrCreate(['interval_start' => $interval], [
            'interval_end' => $end, 'status' => $silent ? 'incomplete' : 'finalized', 'reason' => $silent ? 'no_telemetry_for_interval' : null,
            'domains_with_traffic' => $withTraffic, 'built_at' => now(),
        ]);
    }
}
