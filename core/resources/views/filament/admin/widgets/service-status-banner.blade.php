@php
    $tone = match ($status) {
        'healthy' => 'success', 'degraded' => 'warning', 'critical' => 'danger',
        'maintenance' => 'maintenance', 'stale' => 'stale', default => 'unknown',
    };
    $headline = match ($status) {
        'healthy' => 'All verified systems operational',
        'degraded' => 'Service is degraded',
        'critical' => 'Critical service condition',
        'maintenance' => 'Maintenance controls are active',
        'stale' => 'Operational data is stale',
        default => 'Service condition is unknown',
    };
@endphp

<x-filament-widgets::widget>
    <section class="cdn-service-banner" data-tone="{{ $tone }}" wire:poll.10s aria-labelledby="service-status-heading" aria-live="polite">
        <div class="cdn-service-banner-main">
            <div class="cdn-service-icon" aria-hidden="true">
                <x-filament::icon :icon="$status === 'healthy' ? 'heroicon-o-check-circle' : ($status === 'critical' ? 'heroicon-o-x-circle' : 'heroicon-o-exclamation-triangle')" />
            </div>
            <div class="min-w-0">
                <div class="cdn-eyebrow">{{ str($environment)->headline() }} environment · {{ str($status)->headline() }}</div>
                <h2 id="service-status-heading" class="cdn-service-title">{{ $headline }}</h2>
                <p class="cdn-service-summary">
                    {{ number_format((int) ($state['active_condition_count'] ?? 0)) }} active {{ \Illuminate\Support\Str::plural('condition', (int) ($state['active_condition_count'] ?? 0)) }}
                    @if (($state['affected_domains'] ?? 0) > 0) · {{ number_format((int) $state['affected_domains']) }} affected domains @endif
                    @if (($state['affected_edges'] ?? 0) > 0) · {{ number_format((int) $state['affected_edges']) }} affected edges @endif
                    @if (($state['affected_regions'] ?? 0) > 0) · {{ number_format((int) $state['affected_regions']) }} affected regions @endif
                    @if (($state['affected_domains'] ?? 0) === 0 && ($state['affected_edges'] ?? 0) === 0) · No detected customer or edge impact @endif
                </p>
            </div>
        </div>

        <dl class="cdn-service-facts">
            <div><dt>Condition since</dt><dd>{{ filled($state['started_at'] ?? null) ? \Carbon\CarbonImmutable::parse($state['started_at'])->diffForHumans() : 'Not established' }}</dd></div>
            <div><dt>Last update</dt><dd>{{ filled(data_get($freshness, 'sources.0.timestamp')) ? \Carbon\CarbonImmutable::parse(data_get($freshness, 'sources.0.timestamp'))->diffForHumans() : 'Unavailable' }}</dd></div>
            <div><dt>Updates</dt><dd>Polling active</dd></div>
        </dl>

        <x-filament::button tag="a" :href="$operationsUrl" :color="$status === 'critical' ? 'danger' : 'gray'" icon="heroicon-o-magnifying-glass">
            Investigate
        </x-filament::button>
    </section>
</x-filament-widgets::widget>
