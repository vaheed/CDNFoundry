<x-filament-widgets::widget>
    <x-filament::section heading="DNS health" description="Authoritative response outcomes and cluster health." icon="phosphor-globe">
        <div wire:poll.30s>
            @if (!($state['available'] ?? false))
                <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'No DNS aggregates match this context.'" />
            @elseif (($state['state'] ?? null) === 'no_data')
                <x-ui.widget-state class="cdn-widget-state--compact" state="no_data" title="No DNS traffic in this range" description="The analytics query succeeded; no authoritative query aggregates matched this scope." />
                <dl class="cdn-metric-list">
                    <div><dt>Healthy clusters</dt><dd>{{ number_format((int) data_get($state, 'clusters.healthy', 0)) }} / {{ number_format((int) data_get($state, 'clusters.enabled', 0)) }}</dd></div>
                    <div><dt>Aggregate source</dt><dd>No matching buckets</dd></div>
                </dl>
            @else
                <div class="cdn-metric-hero">
                    <div><div class="cdn-eyebrow">DNS success ratio</div><div class="cdn-metric-hero-value">{{ $successRatio }}</div></div>
                    <x-ui.status-pill :tone="($summary['servfail_ratio'] ?? 0) > 0.01 ? 'danger' : 'success'">SERVFAIL {{ $servfailRatio }}</x-ui.status-pill>
                </div>
                <dl class="cdn-metric-list">
                    <div><dt>Queries</dt><dd>{{ number_format((int) ($summary['queries'] ?? 0)) }}</dd></div>
                    <div><dt>NOERROR</dt><dd>{{ number_format((int) ($summary['successful'] ?? 0)) }}</dd></div>
                    <div><dt>SERVFAIL</dt><dd>{{ number_format((int) ($summary['servfail'] ?? 0)) }}</dd></div>
                    <div><dt>NXDOMAIN</dt><dd>{{ number_format((int) ($summary['nxdomain'] ?? 0)) }}</dd></div>
                    <div><dt>Healthy clusters</dt><dd>{{ number_format((int) data_get($state, 'clusters.healthy', 0)) }} / {{ number_format((int) data_get($state, 'clusters.enabled', 0)) }}</dd></div>
                </dl>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
