<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Data\OpsDashboardContext;
use App\Ops\Services\MetricComparisonService;
use App\Ops\Services\OpsDashboardService;
use App\Ops\Support\MetricFormatter;
use Filament\Widgets\Widget;

class OpsKpiOverview extends Widget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.ops-kpi-overview';

    protected function getViewData(): array
    {
        $context = $this->opsContext();
        $traffic = app(OpsDashboardService::class)->traffic($context);
        $system = app(OpsDashboardService::class)->system($context);
        $formatter = app(MetricFormatter::class);
        $edge = $this->edgeItem($system);
        $dns = $this->dnsItem($context);
        if (! ($traffic['available'] ?? false) || ($traffic['state'] ?? null) === 'no_data') {
            $note = $traffic['error'] ?? (($traffic['state'] ?? null) === 'no_data' ? 'No matching aggregate data' : 'Telemetry unavailable');

            return ['items' => [
                ['label' => 'Requests', 'value' => 'Unavailable', 'note' => $note],
                ['label' => 'Cache hit ratio', 'value' => 'Unavailable', 'note' => $note],
                ['label' => 'Errors', 'value' => 'Unavailable', 'note' => $note],
                $edge,
                $dns,
            ]];
        }

        $current = $traffic['summary'];
        $previous = $traffic['previous_summary'];

        return ['items' => [
            $this->item('Requests', $formatter->number($current['requests']), $current['requests'], $previous['requests'] ?? null, 'neutral', $this->telemetryUrl()),
            $this->item('Cache hit ratio', $formatter->percent($current['cache_ratio']), $current['cache_ratio'], $previous['cache_ratio'] ?? null, ($current['cache_ratio'] ?? 0) >= 0.8 ? 'success' : 'warning', $this->telemetryUrl(['view' => 'cache'])),
            $this->item('Errors', $formatter->percent($current['rate_5xx']), $current['rate_5xx'], $previous['rate_5xx'] ?? null, ($current['rate_5xx'] ?? 0) >= 0.01 ? 'danger' : 'success', $this->telemetryUrl(['status_family' => '5xx'])),
            $edge,
            $dns,
        ]];
    }

    private function item(string $label, string $value, float|int|null $current, float|int|null $previous, string $tone, string $url): array
    {
        $change = app(MetricComparisonService::class)->compare($current, $previous);

        return ['label' => $label, 'value' => $value, 'note' => app(MetricFormatter::class)->deltaPercent($change['percent']), 'tone' => $tone, 'url' => $url];
    }

    private function edgeItem(array $system): array
    {
        $enabled = data_get($system, 'components.edges.details.enabled');
        $stale = data_get($system, 'components.edges.details.stale');
        if (! ($system['available'] ?? false) || ! is_numeric($enabled) || ! is_numeric($stale)) {
            return ['label' => 'Healthy PoPs', 'value' => 'Unavailable', 'note' => 'Edge health unavailable'];
        }

        $healthy = max(0, (int) $enabled - (int) $stale);

        return ['label' => 'Healthy PoPs', 'value' => app(MetricFormatter::class)->number($healthy), 'note' => 'Fresh heartbeats', 'tone' => $healthy > 0 ? 'success' : 'danger', 'url' => EdgeResource::getUrl(panel: 'admin')];
    }

    private function dnsItem(OpsDashboardContext $context): array
    {
        $dns = app(OpsDashboardService::class)->dns($context);
        $ratio = $dns['summary']['success_ratio'] ?? null;
        $url = $this->telemetryUrl(['view' => 'dns']);
        if (! ($dns['available'] ?? false) || $ratio === null) {
            return ['label' => 'DNS success', 'value' => 'Unavailable', 'note' => $dns['error'] ?? 'No DNS traffic in this range', 'url' => $url];
        }

        return ['label' => 'DNS success', 'value' => app(MetricFormatter::class)->percent($ratio), 'tone' => $ratio >= 0.99 ? 'success' : 'warning', 'url' => $url];
    }
}
