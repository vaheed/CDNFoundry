<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\OpsDashboardService;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

class TrafficOverviewChart extends ChartWidget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 6];

    protected ?string $heading = 'Traffic and egress';

    protected ?string $description = 'Hourly request volume and egress. Dashed requests are the previous equivalent period.';

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
        return $this->telemetryUrl(['view' => 'traffic']);
    }

    protected function getData(): array
    {
        $state = $this->getDisplayState();
        $current = $state['current'] ?? [];
        $previous = $state['previous'] ?? [];
        $datasets = [
            [
                'label' => 'Requests',
                'data' => array_map('floatval', array_column($current, 'requests')),
                'borderColor' => 'rgb(37, 99, 235)',
                'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
                'fill' => true,
                'tension' => 0.25,
                'yAxisID' => 'y',
            ],
            [
                'label' => 'Egress (MiB)',
                'data' => array_map(fn (array $row): float => round((float) $row['bytes_out'] / 1048576, 3), $current),
                'borderColor' => 'rgb(8, 145, 178)',
                'backgroundColor' => 'rgba(8, 145, 178, 0.08)',
                'tension' => 0.25,
                'yAxisID' => 'y1',
            ],
        ];
        if ($previous !== []) {
            $datasets[] = [
                'label' => 'Previous requests',
                'data' => array_map('floatval', array_column($previous, 'requests')),
                'borderColor' => 'rgba(100, 116, 139, 0.8)',
                'borderDash' => [6, 5],
                'pointRadius' => 0,
                'tension' => 0.25,
                'yAxisID' => 'y',
            ];
        }

        return ['datasets' => $datasets, 'labels' => $this->labels($current)];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Requests']],
                'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Egress (MiB)']],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function labels(array $rows): array
    {
        return array_map(fn (array $row): string => isset($row['bucket']) ? CarbonImmutable::parse($row['bucket'], 'UTC')->format('M j H:i') : 'Unknown', $rows);
    }
}
