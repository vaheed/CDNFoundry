<x-filament-widgets::widget>
    <x-filament::section heading="Cache efficiency" description="Request outcomes and bytes served from cache for the shared investigation period." icon="heroicon-o-circle-stack">
        <div wire:poll.30s>
            @if (!($state['available'] ?? false) || ($state['state'] ?? null) === 'no_data')
                <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'No cache outcomes match this context.'" />
            @else
                <div class="cdn-metric-hero">
                    <div><div class="cdn-eyebrow">Cache hit ratio</div><div class="cdn-metric-hero-value">{{ $ratio }}</div></div>
                    <x-ui.status-pill :tone="($summary['cache_ratio'] ?? 0) >= 0.8 ? 'success' : 'warning'">{{ $comparison }}</x-ui.status-pill>
                </div>
                <dl class="cdn-metric-list">
                    <div><dt>Hit</dt><dd>{{ number_format((int) ($summary['cache_hits'] ?? 0)) }}</dd></div>
                    <div><dt>Miss</dt><dd>{{ number_format((int) ($summary['cache_misses'] ?? 0)) }}</dd></div>
                    <div><dt>Bypass</dt><dd>{{ number_format((int) ($summary['cache_bypass'] ?? 0)) }}</dd></div>
                    <div><dt>Stale</dt><dd>{{ number_format((int) ($summary['cache_stale'] ?? 0)) }}</dd></div>
                    <div><dt>Cache-served egress</dt><dd>{{ $cacheBytes }}</dd></div>
                    <div><dt>Estimated financial savings</dt><dd>Unavailable</dd></div>
                </dl>
                <a class="cdn-widget-link" href="{{ $telemetryUrl }}">Open cache analytics <span aria-hidden="true">→</span></a>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
