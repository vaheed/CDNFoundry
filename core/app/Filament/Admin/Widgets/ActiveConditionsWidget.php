<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\DnsClusters\DnsClusterResource;
use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Filament\Admin\Resources\Operations\OperationResource;
use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\OpsDashboardService;
use Filament\Widgets\Widget;

class ActiveConditionsWidget extends Widget
{
    use UsesOpsDashboardContext;

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 5];

    protected string $view = 'filament.admin.widgets.active-conditions';

    protected function getViewData(): array
    {
        return [
            'state' => app(OpsDashboardService::class)->system($this->opsContext()),
            'conditionUrl' => fn (string $condition): string => match ($condition) {
                'edge_capacity', 'edges', 'edge_listeners', 'edge_cells', 'service_pools' => EdgeResource::getUrl(panel: 'admin'),
                'authoritative_dns', 'dns_deployments' => DnsClusterResource::getUrl(panel: 'admin'),
                default => OperationResource::failedUrl(),
            },
        ];
    }
}
