<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\OpsDashboardService;
use Filament\Widgets\Widget;

class QueueHealthWidget extends Widget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.queue-health';

    protected function getViewData(): array
    {
        return ['state' => app(OpsDashboardService::class)->queues($this->opsContext())];
    }
}
