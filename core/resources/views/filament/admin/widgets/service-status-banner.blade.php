@php
    $tone = match ($status) {
        'healthy' => 'success', 'degraded' => 'warning', 'critical' => 'danger',
        'maintenance' => 'maintenance', 'stale' => 'warning', default => 'unknown',
    };
    $headline = match ($status) {
        'healthy' => 'All verified systems operational',
        'degraded' => 'Service is degraded',
        'critical' => 'Critical service condition',
        'maintenance' => 'Maintenance controls are active',
        'stale' => 'Operational data is stale',
        default => 'Service condition is unknown',
    };
    $conditions = (int) ($state['active_condition_count'] ?? 0);
@endphp

<x-filament-widgets::widget>
    <section class="cdn-overview-status" data-testid="overview-status" wire:poll.10s aria-label="Service status" aria-live="polite">
        <x-ui.status-pill :tone="$tone">{{ str($status)->headline() }}</x-ui.status-pill>
        <span class="cdn-overview-status-title">{{ $headline }}</span>
        <span class="cdn-overview-status-meta">
            <span class="cdn-live" data-testid="overview-live">Polling active</span>
            · {{ number_format($conditions) }} {{ \Illuminate\Support\Str::plural('condition', $conditions) }}
            · checked <time class="cdn-mono" title="{{ $this->dashboardTimestamp($state['checked_at'] ?? null) }}">{{ filled($state['checked_at'] ?? null) ? \Carbon\CarbonImmutable::parse($state['checked_at'])->diffForHumans() : 'unavailable' }}</time>
            · {{ str($environment)->headline() }}
        </span>
    </section>
</x-filament-widgets::widget>
