<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Data\OpsDashboardContext;
use App\Ops\Services\MetricComparisonService;
use App\Ops\Services\OpsDashboardService;
use App\Ops\Support\MetricFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpsKpiOverview extends StatsOverviewWidget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '15s';

    protected int|array|null $columns = ['default' => 2, 'xl' => 5];

    protected function getStats(): array
    {
        $context = $this->opsContext();
        $traffic = app(OpsDashboardService::class)->traffic($context);
        $system = app(OpsDashboardService::class)->system($context);
        $formatter = app(MetricFormatter::class);
        $edgeStat = $this->edgeStat($system);
        $dnsStat = $this->dnsStat($context);
        if (! ($traffic['available'] ?? false) || ($traffic['state'] ?? null) === 'no_data') {
            $description = $traffic['error'] ?? (($traffic['state'] ?? null) === 'no_data' ? 'No matching aggregate data' : 'Telemetry unavailable');

            return collect(['Requests', 'Cache hit ratio', 'Errors'])
                ->map(fn (string $label): Stat => Stat::make($label, 'Unavailable')->description($description)->color('gray'))
                ->push($edgeStat, $dnsStat)
                ->all();
        }

        $current = $traffic['summary'];
        $previous = $traffic['previous_summary'];

        return [
            $this->stat('Requests', $formatter->number($current['requests']), $current['requests'], $previous['requests'] ?? null, array_column($traffic['current'], 'requests'), 'primary', $this->telemetryUrl()),
            $this->stat('Cache hit ratio', $formatter->percent($current['cache_ratio']), $current['cache_ratio'], $previous['cache_ratio'] ?? null, array_column($traffic['current'], 'cache_hits'), ($current['cache_ratio'] ?? 0) >= 0.8 ? 'success' : 'warning', $this->telemetryUrl(['view' => 'cache'])),
            $this->stat('Errors', $formatter->percent($current['rate_5xx']), $current['rate_5xx'], $previous['rate_5xx'] ?? null, array_column($traffic['current'], 'requests_5xx'), ($current['rate_5xx'] ?? 0) >= 0.01 ? 'danger' : 'success', $this->telemetryUrl(['status_family' => '5xx'])),
            $edgeStat,
            $dnsStat,
        ];
    }

    private function dnsStat(OpsDashboardContext $context): Stat
    {
        $dns = app(OpsDashboardService::class)->dns($context);
        $ratio = $dns['summary']['success_ratio'] ?? null;
        if (! ($dns['available'] ?? false) || $ratio === null) {
            return Stat::make('DNS success', 'Unavailable')->description($dns['error'] ?? 'No DNS traffic in this range')->color('gray')
                ->url($this->telemetryUrl(['view' => 'dns']));
        }

        return Stat::make('DNS success', app(MetricFormatter::class)->percent($ratio))
            ->color($ratio >= 0.99 ? 'success' : 'warning')
            ->url($this->telemetryUrl(['view' => 'dns']));
    }

    private function edgeStat(array $system): Stat
    {
        $enabled = data_get($system, 'components.edges.details.enabled');
        $stale = data_get($system, 'components.edges.details.stale');
        if (! ($system['available'] ?? false) || ! is_numeric($enabled) || ! is_numeric($stale)) {
            return Stat::make('Healthy PoPs', 'Unavailable')
                ->description('Edge health unavailable')->color('gray');
        }

        $healthy = max(0, (int) $enabled - (int) $stale);

        return Stat::make('Healthy PoPs', app(MetricFormatter::class)->number($healthy))
            ->description('Fresh heartbeats')
            ->chart([(float) $healthy])->color($healthy > 0 ? 'success' : 'danger')
            ->url(EdgeResource::getUrl(panel: 'admin'));
    }

    private function stat(string $label, string $value, float|int|null $current, float|int|null $previous, array $chart, string $color, string $url): Stat
    {
        $change = app(MetricComparisonService::class)->compare($current, $previous);
        $description = app(MetricFormatter::class)->deltaPercent($change['percent']);
        $icon = match ($change['direction']) {
            'up' => 'heroicon-m-arrow-trending-up',
            'down' => 'heroicon-m-arrow-trending-down',
            'flat' => 'heroicon-m-minus',
            default => 'heroicon-m-information-circle',
        };

        return Stat::make($label, $value)
            ->description($description)
            ->descriptionIcon($icon)
            ->chart(array_map('floatval', $chart))
            ->color($color)
            ->url($url);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function originLatencyChart(array $rows): array
    {
        return array_map(fn (array $row): float => (float) $row['origin_latency_samples'] > 0
            ? (float) $row['origin_latency_sum_ms'] / (float) $row['origin_latency_samples']
            : 0.0, $rows);
    }
}
