<?php

namespace App\Filament\Admin\Widgets\Concerns;

use App\Filament\Admin\Pages\Telemetry;
use App\Models\User;
use App\Ops\Data\OpsDashboardContext;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait UsesOpsDashboardContext
{
    use InteractsWithPageFilters;

    /** False where the chart is already on the telemetry page it would link to. */
    public bool $showDrilldown = true;

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

    public function dashboardTimestamp(?string $timestamp, string $format = 'Y-m-d H:i:s'): string
    {
        return app(PlatformSettings::class)->formatTimestamp($timestamp, $format);
    }

    /** Hour label in the display timezone; the hour still in progress says so. */
    public function bucketLabel(?string $bucket): string
    {
        if ($bucket === null) {
            return 'Unknown';
        }
        $label = $this->dashboardTimestamp($bucket, 'M j H:i');

        return CarbonImmutable::parse($bucket, 'UTC')->equalTo(CarbonImmutable::now('UTC')->startOfHour()) ? $label.' (so far)' : $label;
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
