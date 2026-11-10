<?php

namespace App\Filament\Domain\Widgets;

use App\Filament\Domain\Widgets\Concerns\UsesAnalyticsFilters;
use App\Models\UsageRollup;
use Filament\Widgets\Widget;

/** Finalized usage from PostgreSQL rollups, still available when ClickHouse is not. */
class AnalyticsUsage extends Widget
{
    use UsesAnalyticsFilters;

    protected string $view = 'filament.domain.widgets.analytics.usage';

    protected int|string|array $columnSpan = 'full';

    public int $limit = 10;

    public function showMore(): void
    {
        $this->limit = min(100, $this->limit + 20);
    }

    public function showFewer(): void
    {
        $this->limit = 10;
    }

    protected function getViewData(): array
    {
        $domains = $this->analyticsScope()['domains'];
        $range = $this->analyticsRange();
        $rows = UsageRollup::query()->with('domain:id,name')
            ->whereIn('domain_id', array_map(fn ($domain): int => (int) $domain->getKey(), $domains))
            ->where('status', 'finalized')
            ->where('interval_start', '>=', $range['from'])->where('interval_start', '<', $range['to'])
            ->latest('interval_start')->orderBy('domain_id')->limit($this->limit + 1)->get();

        return [
            'multiple' => count($domains) > 1,
            'csvDomain' => count($domains) === 1 ? $domains[0] : null,
            'rows' => $rows->take($this->limit)->map(fn (UsageRollup $row): array => [
                'domain' => $row->domain?->name,
                'interval' => $row->interval_start->toIso8601String(),
                'requests' => number_format($row->requests),
                'bytes' => $this->formatBytes($row->bytes_in + $row->bytes_out),
                'dns' => number_format($row->dns_queries),
            ])->all(),
            'hasMore' => $rows->count() > $this->limit,
        ];
    }
}
