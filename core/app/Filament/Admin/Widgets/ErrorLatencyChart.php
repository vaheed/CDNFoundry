<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\OpsDashboardService;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

class ErrorLatencyChart extends ChartWidget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 6];

    protected ?string $heading = 'Errors and origin latency';

    protected ?string $description = '4xx/5xx rates and valid origin average latency. Percentile latency is not present in the aggregate schema.';

    protected ?string $pollingInterval = '15s';

    protected ?string $maxHeight = '320px';

    protected string $view = 'filament.admin.widgets.ops-chart';

    /** @return array<string, mixed> */
    public function getDisplayState(): array
    {
        return app(OpsDashboardService::class)->traffic($this->opsContext());
    }

    public function getDrilldownUrl(): string
    {
        return $this->telemetryUrl(['view' => 'origin', 'status_family' => '5xx']);
    }

    protected function getData(): array
    {
        $rows = $this->getDisplayState()['current'] ?? [];

        return [
            'datasets' => [
                ['label' => '4xx rate (%)', 'data' => $this->rates($rows, 'requests_4xx'), 'borderColor' => 'rgb(217, 119, 6)', 'tension' => 0.25, 'yAxisID' => 'y'],
                ['label' => '5xx rate (%)', 'data' => $this->rates($rows, 'requests_5xx'), 'borderColor' => 'rgb(220, 38, 38)', 'backgroundColor' => 'rgba(220, 38, 38, 0.08)', 'fill' => true, 'tension' => 0.25, 'yAxisID' => 'y'],
                ['label' => 'Origin average (ms)', 'data' => $this->latency($rows), 'borderColor' => 'rgb(124, 58, 237)', 'borderDash' => [5, 4], 'tension' => 0.25, 'yAxisID' => 'y1'],
            ],
            'labels' => array_map(fn (array $row): string => isset($row['bucket']) ? CarbonImmutable::parse($row['bucket'], 'UTC')->format('M j H:i') : 'Unknown', $rows),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Error rate (%)']],
                'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Milliseconds']],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function rates(array $rows, string $field): array
    {
        return array_map(fn (array $row): float => (float) $row['requests'] > 0 ? round(((float) $row[$field] / (float) $row['requests']) * 100, 4) : 0.0, $rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function latency(array $rows): array
    {
        return array_map(fn (array $row): ?float => (float) $row['origin_latency_samples'] > 0 ? round((float) $row['origin_latency_sum_ms'] / (float) $row['origin_latency_samples'], 2) : null, $rows);
    }
}
