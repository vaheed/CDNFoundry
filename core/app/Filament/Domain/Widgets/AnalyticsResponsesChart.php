<?php

namespace App\Filament\Domain\Widgets;

use App\Filament\Domain\Widgets\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class AnalyticsResponsesChart extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected string $view = 'filament.domain.widgets.analytics.chart';

    protected ?string $heading = 'Responses by status';

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
        $successful = fn (array $row): float => max(0, ($row['requests'] ?? 0) - ($row['requests_4xx'] ?? 0) - ($row['requests_5xx'] ?? 0));

        return [
            'labels' => array_map(fn (array $row): string => $this->bucketLabel($row['bucket'], $series['bucket']), $rows),
            'datasets' => [
                ['label' => 'Success and redirects', 'data' => array_map($successful, $rows), 'backgroundColor' => 'rgba(22, 163, 74, 0.75)', 'stack' => 'responses'],
                ['label' => 'Client errors (4xx)', 'data' => array_map(fn (array $row): float => $row['requests_4xx'] ?? 0, $rows), 'backgroundColor' => 'rgba(217, 119, 6, 0.85)', 'stack' => 'responses'],
                ['label' => 'Server errors (5xx)', 'data' => array_map(fn (array $row): float => $row['requests_5xx'] ?? 0, $rows), 'backgroundColor' => 'rgba(220, 38, 38, 0.9)', 'stack' => 'responses'],
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
                'x' => ['stacked' => true, 'ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0]],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Requests']],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
