<?php

namespace App\Filament\Admin\Widgets\Concerns;

use App\Filament\Admin\Pages\Telemetry;
use App\Models\User;
use App\Ops\Data\OpsDashboardContext;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait UsesOpsDashboardContext
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return auth()->user() instanceof User && auth()->user()->isAdmin() && ! auth()->user()->isDisabled();
    }

    protected function opsContext(): OpsDashboardContext
    {
        /** @var User|null $user */
        $user = auth()->user();

        return OpsDashboardContext::fromFilters($this->pageFilters, $user);
    }

    protected function telemetryUrl(array $extra = []): string
    {
        $context = $this->opsContext();

        return Telemetry::getUrl(array_filter([
            'range' => $context->range,
            'domain' => $context->domainId,
            'edge' => $context->edgeId,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null), panel: 'admin');
    }
}
