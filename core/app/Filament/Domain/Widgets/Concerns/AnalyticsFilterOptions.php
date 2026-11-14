<?php

namespace App\Filament\Domain\Widgets\Concerns;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Builder;

/** Range choices, retention bounds, and the domain scope of the domain-user Analytics page. */
final class AnalyticsFilterOptions
{
    public const RANGES = ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'custom' => 'Custom dates'];

    /** Hourly aggregates are kept for 400 days; raw events for 7. */
    public const HOURLY_RETENTION_DAYS = 399;

    public const RAW_RETENTION_DAYS = 7;

    public const MAX_RANGE_DAYS = 92;

    /** @return Builder<Domain> */
    public static function assignedDomains(): Builder
    {
        return Domain::query()->whereHas('users', fn (Builder $users): Builder => $users->whereKey(auth()->id()));
    }
}
