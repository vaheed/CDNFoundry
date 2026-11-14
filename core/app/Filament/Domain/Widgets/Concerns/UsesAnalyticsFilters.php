<?php

namespace App\Filament\Domain\Widgets\Concerns;

use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns the Analytics page filters into a bounded query scope: only domains assigned to the signed-in user,
 * at most AnalyticsStore::MAX_SCOPE_DOMAINS of them, and a range inside the hourly retention.
 */
trait UsesAnalyticsFilters
{
    use InteractsWithPageFilters;

    /** @return array{domains: list<Domain>, total: int, selected: bool} */
    protected function analyticsScope(): array
    {
        $selected = array_values(array_filter(array_map('intval', (array) ($this->pageFilters['domains'] ?? [])), fn (int $id): bool => $id > 0));
        $query = AnalyticsFilterOptions::assignedDomains()->when($selected !== [], fn (Builder $query) => $query->whereKey($selected));
        $total = (clone $query)->count();

        return [
            'domains' => $query->orderBy('name')->limit(AnalyticsStore::MAX_SCOPE_DOMAINS)->get()->all(),
            'total' => $total,
            'selected' => $selected !== [],
        ];
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, raw: false, bucket: string, error: ?string} */
    protected function analyticsRange(): array
    {
        $now = CarbonImmutable::now('UTC');
        $range = (string) ($this->pageFilters['range'] ?? '24h');
        $result = fn (CarbonImmutable $from, CarbonImmutable $to, ?string $error = null): array => [
            'from' => $from, 'to' => $to, 'raw' => false, 'error' => $error,
            'bucket' => $from->diffInHours($to) > 7 * 24 ? 'day' : 'hour',
        ];
        if ($range !== 'custom') {
            return $result($now->subSeconds(['7d' => 604800, '30d' => 2592000][$range] ?? 86400), $now);
        }
        $timezone = app(PlatformSettings::class)->displayTimezone();
        try {
            $from = CarbonImmutable::parse((string) ($this->pageFilters['from'] ?? ''), $timezone)->startOfDay()->utc();
            $to = CarbonImmutable::parse((string) ($this->pageFilters['to'] ?? ''), $timezone)->addDay()->startOfDay()->utc();
        } catch (\Throwable) {
            return $result($now->subDay(), $now, 'Choose a start and end date.');
        }
        $to = $to->min($now);
        $error = match (true) {
            blank($this->pageFilters['from'] ?? null) || blank($this->pageFilters['to'] ?? null) => 'Choose a start and end date.',
            $from->greaterThanOrEqualTo($to) => 'The start date must be before the end date.',
            $from->lessThan($now->subDays(AnalyticsFilterOptions::HOURLY_RETENTION_DAYS)) => 'Analytics are kept for '.AnalyticsFilterOptions::HOURLY_RETENTION_DAYS.' days. Choose a later start date.',
            $from->diffInDays($to) > AnalyticsFilterOptions::MAX_RANGE_DAYS => 'Choose a range of '.AnalyticsFilterOptions::MAX_RANGE_DAYS.' days or less.',
            default => null,
        };

        return $error === null ? $result($from, $to) : $result($now->subDay(), $now, $error);
    }

    /**
     * Raw events (paths, logs) cover at most the last 24 hours of the range and exist for 7 days.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, raw: true}|null
     */
    protected function analyticsRawRange(): ?array
    {
        $range = $this->analyticsRange();
        $oldest = CarbonImmutable::now('UTC')->subDays(AnalyticsFilterOptions::RAW_RETENTION_DAYS);
        if ($range['to']->lessThanOrEqualTo($oldest)) {
            return null;
        }
        $from = $range['from']->max($range['to']->subDay())->max($oldest);

        return ['from' => $from, 'to' => $range['to'], 'raw' => true];
    }

    /**
     * Hourly or daily traffic for the scope, one row per bucket with empty buckets as zero.
     *
     * @return array{state: string, bucket: string, rows: list<array<string, mixed>>}
     */
    protected function analyticsSeries(): array
    {
        $scope = $this->analyticsScope();
        $range = $this->analyticsRange();
        if ($scope['domains'] === []) {
            return ['state' => 'no_data', 'bucket' => $range['bucket'], 'rows' => []];
        }
        try {
            $rows = app(AnalyticsStore::class)->operationalTrafficSeries($scope['domains'], $range, null, $range['bucket']);
        } catch (\Throwable) {
            return ['state' => 'unavailable', 'bucket' => $range['bucket'], 'rows' => []];
        }
        $byBucket = collect($rows)->keyBy(fn (array $row): string => CarbonImmutable::parse((string) $row['bucket'], 'UTC')->format('Y-m-d H:i'));
        $step = $range['bucket'] === 'day' ? 'addDay' : 'addHour';
        $cursor = $range['bucket'] === 'day' ? $range['from']->startOfDay() : $range['from']->startOfHour();
        $filled = [];
        for (; $cursor->lessThan($range['to']) && count($filled) < 200; $cursor = $cursor->{$step}()) {
            $filled[] = ['bucket' => $cursor->toIso8601String(), ...array_map('floatval', array_diff_key($byBucket->get($cursor->format('Y-m-d H:i'), []), ['bucket' => true]))];
        }

        return ['state' => $rows === [] ? 'no_data' : 'ready', 'bucket' => $range['bucket'], 'rows' => $filled];
    }

    public function bucketLabel(string $bucket, string $size): string
    {
        return $this->displayTime($bucket, $size === 'day' ? 'M j' : 'M j H:i');
    }

    public function formatBytes(int|float|string|null $value): string
    {
        $bytes = max(0, (float) ($value ?? 0));
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $index = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return number_format($bytes / (1024 ** $index), $index === 0 ? 0 : 1).' '.$units[$index];
    }

    public function displayTime(?string $timestamp, string $format = 'Y-m-d H:i'): string
    {
        return app(PlatformSettings::class)->formatTimestamp($timestamp, $format);
    }
}
