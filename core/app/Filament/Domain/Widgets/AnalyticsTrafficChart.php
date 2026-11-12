<?php

namespace App\Filament\Domain\Widgets;

use App\Filament\Domain\Widgets\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class AnalyticsTrafficChart extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected string $view = 'filament.domain.widgets.analytics.chart';

    protected ?string $heading = 'Requests and bandwidth';

    protected ?string $maxHeight = '280px';

    /** @var array{state: string, bucket: string, rows: list<array<string, mixed>>}|null */
    private ?array $series = null;

    public function getSeries(): array
    {
        return $this->series ??= $this->analyticsSeries();
    }

    protected function getData(): array
    {
        $series = $this->getSeries();
        $rows = $series['rows'];

        return [
            'labels' => array_map(fn (array $row): string => $this->bucketLabel($row['bucket'], $series['bucket']), $rows),
            'datasets' => [
                ['label' => 'Requests', 'data' => array_map(fn (array $row): float => $row['requests'] ?? 0, $rows), 'borderColor' => 'rgb(37, 99, 235)', 'backgroundColor' => 'rgba(37, 99, 235, 0.1)', 'fill' => true, 'tension' => 0.25, 'pointRadius' => 0, 'yAxisID' => 'y'],
                ['label' => 'From cache', 'data' => array_map(fn (array $row): float => $row['cache_hits'] ?? 0, $rows), 'borderColor' => 'rgb(22, 163, 74)', 'tension' => 0.25, 'pointRadius' => 0, 'yAxisID' => 'y'],
                ['label' => 'Bandwidth (MiB)', 'data' => array_map(fn (array $row): float => round((($row['bytes_in'] ?? 0) + ($row['bytes_out'] ?? 0)) / 1048576, 2), $rows), 'borderColor' => 'rgb(8, 145, 178)', 'borderDash' => [5, 4], 'tension' => 0.25, 'pointRadius' => 0, 'yAxisID' => 'y1'],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'x' => ['ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0]],
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Requests']],
                'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'MiB']],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
