<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\MetricComparisonService;
use App\Ops\Services\OpsDashboardService;
use App\Ops\Support\MetricFormatter;
use Filament\Widgets\Widget;

class CacheEfficiencyWidget extends Widget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 6];

    protected string $view = 'filament.admin.widgets.cache-efficiency';

    protected function getViewData(): array
    {
        $state = app(OpsDashboardService::class)->traffic($this->opsContext());
        $summary = $state['summary'] ?? [];
        $previous = $state['previous_summary'] ?? [];
        $formatter = app(MetricFormatter::class);
        $change = app(MetricComparisonService::class)->compare($summary['cache_ratio'] ?? null, $previous['cache_ratio'] ?? null);

        return [
            'state' => $state,
            'summary' => $summary,
            'ratio' => $formatter->percent($summary['cache_ratio'] ?? null),
            'cacheBytes' => $formatter->bytes($summary['cache_bytes_out'] ?? null),
            'comparison' => $formatter->deltaPercent($change['percent']),
            'telemetryUrl' => $this->telemetryUrl(['view' => 'cache']),
        ];
    }
}
