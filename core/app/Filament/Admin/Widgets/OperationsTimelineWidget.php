<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\Operations\OperationResource;
use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\OpsDashboardService;
use Filament\Widgets\Widget;

class OperationsTimelineWidget extends Widget
{
    use UsesOpsDashboardContext;

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.operations-timeline';

    protected function getViewData(): array
    {
        return [
            'state' => app(OpsDashboardService::class)->operations($this->opsContext()),
            'operationsUrl' => OperationResource::getUrl(panel: 'admin'),
            'auditsUrl' => AuditLogResource::getUrl(panel: 'admin'),
        ];
    }
}
