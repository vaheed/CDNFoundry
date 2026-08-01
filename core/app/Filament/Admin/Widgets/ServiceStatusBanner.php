<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Operations\OperationResource;
use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Ops\Services\OpsDashboardService;
use Filament\Widgets\Widget;

class ServiceStatusBanner extends Widget
{
    use UsesOpsDashboardContext;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.service-status-banner';

    protected function getViewData(): array
    {
        $context = $this->opsContext();
        $service = app(OpsDashboardService::class);
        $system = $service->system($context);
        $freshness = $service->freshness($context);
        $status = $system['state'] ?? 'unknown';
        if ($status === 'healthy' && in_array($freshness['state'] ?? 'unavailable', ['delayed', 'stale'], true)) {
            $status = 'stale';
        } elseif ($status === 'healthy' && in_array($freshness['state'] ?? 'unavailable', ['unavailable', 'no_data'], true)) {
            $status = 'unknown';
        }

        return [
            'status' => $status,
            'state' => $system,
            'freshness' => $freshness,
            'operationsUrl' => OperationResource::failedUrl(),
            'environment' => app()->environment(),
        ];
    }
}
