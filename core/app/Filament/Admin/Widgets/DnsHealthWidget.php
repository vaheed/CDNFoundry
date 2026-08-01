<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\DnsClusters\DnsClusterResource;
use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\OpsDashboardService;
use App\Ops\Support\MetricFormatter;
use Filament\Widgets\Widget;

class DnsHealthWidget extends Widget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 6];

    protected string $view = 'filament.admin.widgets.dns-health';

    protected function getViewData(): array
    {
        $state = app(OpsDashboardService::class)->dns($this->opsContext());
        $summary = $state['summary'] ?? [];
        $formatter = app(MetricFormatter::class);

        return [
            'state' => $state,
            'summary' => $summary,
            'successRatio' => $formatter->percent($summary['success_ratio'] ?? null),
            'servfailRatio' => $formatter->percent($summary['servfail_ratio'] ?? null),
            'clustersUrl' => DnsClusterResource::getUrl(panel: 'admin'),
            'telemetryUrl' => $this->telemetryUrl(['view' => 'dns']),
        ];
    }
}
