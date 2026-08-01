<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\OpsDashboardService;
use Filament\Widgets\Widget;

class DataFreshnessWidget extends Widget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 4];

    protected string $view = 'filament.admin.widgets.data-freshness';

    protected function getViewData(): array
    {
        return ['state' => app(OpsDashboardService::class)->freshness($this->opsContext())];
    }
}
