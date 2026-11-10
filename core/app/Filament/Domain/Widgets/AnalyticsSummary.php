<?php

namespace App\Filament\Domain\Widgets;

use App\Filament\Domain\Widgets\Concerns\UsesAnalyticsFilters;
use App\Support\AnalyticsStore;
use Filament\Widgets\Widget;
use Throwable;

class AnalyticsSummary extends Widget
{
    use UsesAnalyticsFilters;

    protected string $view = 'filament.domain.widgets.analytics.summary';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $scope = $this->analyticsScope();
        $range = $this->analyticsRange();
        $store = app(AnalyticsStore::class);
        $count = count($scope['domains']);
        $label = match (true) {
            $count === 1 => $scope['domains'][0]->name,
            $scope['total'] > $count => "The first {$count} of {$scope['total']} domains by name",
            $scope['selected'] => "{$count} selected domains",
            default => "All {$count} domains",
        };
        $base = ['label' => $label, 'scope' => $scope, 'range' => $range, 'meta' => $store->metadata($range), 'state' => 'ready', 'items' => []];
        if ($scope['domains'] === []) {
            return [...$base, 'state' => 'empty'];
        }
        try {
            $summary = $store->summary($scope['domains'], $range);
        } catch (Throwable) {
            return [...$base, 'state' => 'unavailable'];
        }
        $requests = (int) ($summary['requests'] ?? 0);

        return [...$base, 'items' => [
            ['label' => 'Requests', 'value' => number_format($requests)],
            ['label' => 'Bandwidth', 'value' => $this->formatBytes(((int) ($summary['bytes_in'] ?? 0)) + ((int) ($summary['bytes_out'] ?? 0)))],
            ['label' => 'Cache hit ratio', 'value' => number_format(((float) ($summary['cache_ratio'] ?? 0)) * 100, 1).'%', 'note' => number_format((int) ($summary['cache_hits'] ?? 0)).' from cache'],
            ['label' => 'Origin errors', 'value' => number_format((int) ($summary['origin_errors'] ?? 0)), 'tone' => ((int) ($summary['origin_errors'] ?? 0)) > 0 ? 'warning' : 'neutral'],
            ['label' => 'DNS queries', 'value' => number_format((int) ($summary['dns_queries'] ?? 0))],
        ]];
    }
}
