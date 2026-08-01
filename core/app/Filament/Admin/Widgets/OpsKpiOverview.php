<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\MetricComparisonService;
use App\Ops\Services\OpsDashboardService;
use App\Ops\Support\MetricFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpsKpiOverview extends StatsOverviewWidget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Operational KPIs';

    protected ?string $description = 'Latest complete hourly aggregates. Comparison uses the previous equivalent complete period.';

    protected ?string $pollingInterval = '15s';

    protected int|array|null $columns = ['md' => 2, 'xl' => 4];

    protected function getStats(): array
    {
        $context = $this->opsContext();
        $traffic = app(OpsDashboardService::class)->traffic($context);
        $system = app(OpsDashboardService::class)->system($context);
        $formatter = app(MetricFormatter::class);
        if (! ($traffic['available'] ?? false) || ($traffic['state'] ?? null) === 'no_data') {
            $description = $traffic['error'] ?? (($traffic['state'] ?? null) === 'no_data' ? 'No matching aggregate data' : 'Telemetry unavailable');

            return collect(['Requests', 'Egress', 'Cache hit ratio', '4xx rate', '5xx rate', 'Origin latency', 'Healthy edges'])
                ->map(fn (string $label): Stat => Stat::make($label, 'Unavailable')->description($description)->color('gray'))
                ->all();
        }

        $current = $traffic['summary'];
        $previous = $traffic['previous_summary'];
        $healthyEdges = (int) data_get($system, 'components.edges.details.enabled', 0) - (int) data_get($system, 'components.edges.details.stale', 0);
        $edgeChart = [(float) max(0, $healthyEdges)];

        return [
            $this->stat('Requests', $formatter->number($current['requests']), $current['requests'], $previous['requests'] ?? null, array_column($traffic['current'], 'requests'), 'primary', $this->telemetryUrl()),
            $this->stat('Egress', $formatter->bytes($current['bytes_out']), $current['bytes_out'], $previous['bytes_out'] ?? null, array_column($traffic['current'], 'bytes_out'), 'info', $this->telemetryUrl()),
            $this->stat('Cache hit ratio', $formatter->percent($current['cache_ratio']), $current['cache_ratio'], $previous['cache_ratio'] ?? null, array_column($traffic['current'], 'cache_hits'), ($current['cache_ratio'] ?? 0) >= 0.8 ? 'success' : 'warning', $this->telemetryUrl(['view' => 'cache'])),
            $this->stat('4xx rate', $formatter->percent($current['rate_4xx']), $current['rate_4xx'], $previous['rate_4xx'] ?? null, array_column($traffic['current'], 'requests_4xx'), ($current['rate_4xx'] ?? 0) >= 0.1 ? 'warning' : 'success', $this->telemetryUrl(['status_family' => '4xx'])),
            $this->stat('5xx rate', $formatter->percent($current['rate_5xx']), $current['rate_5xx'], $previous['rate_5xx'] ?? null, array_column($traffic['current'], 'requests_5xx'), ($current['rate_5xx'] ?? 0) >= 0.01 ? 'danger' : 'success', $this->telemetryUrl(['status_family' => '5xx'])),
            $this->stat('Origin latency', $formatter->milliseconds($current['origin_average_latency_ms']), $current['origin_average_latency_ms'], $previous['origin_average_latency_ms'] ?? null, $this->originLatencyChart($traffic['current']), ($current['origin_average_latency_ms'] ?? 0) >= 1000 ? 'danger' : (($current['origin_average_latency_ms'] ?? 0) >= 500 ? 'warning' : 'success'), $this->telemetryUrl(['view' => 'origin'])),
            Stat::make('Healthy edges', $formatter->number(max(0, $healthyEdges)))
                ->description('Fresh enabled edge heartbeats')
                ->chart($edgeChart)->color($healthyEdges > 0 ? 'success' : 'danger')->url(EdgeResource::getUrl(panel: 'admin')),
        ];
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
