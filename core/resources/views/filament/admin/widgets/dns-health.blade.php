<x-filament-widgets::widget>
    <x-filament::section heading="DNS health" description="Authoritative response outcomes plus desired cluster health. DNS latency is not collected in the current aggregate schema." icon="heroicon-o-globe-alt">
        <div wire:poll.30s>
            @if (!($state['available'] ?? false))
                <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'No DNS aggregates match this context.'" />
            @elseif (($state['state'] ?? null) === 'no_data')
                <x-ui.widget-state class="cdn-widget-state--compact" state="no_data" title="No DNS traffic in this range" description="The analytics query succeeded; no authoritative query aggregates matched this scope." />
                <dl class="cdn-metric-list">
                    <div><dt>Healthy clusters</dt><dd>{{ number_format((int) data_get($state, 'clusters.healthy', 0)) }} / {{ number_format((int) data_get($state, 'clusters.enabled', 0)) }}</dd></div>
                    <div><dt>Aggregate source</dt><dd>No matching buckets</dd></div>
                </dl>
                <div class="flex flex-wrap gap-4"><a class="cdn-widget-link" href="{{ $telemetryUrl }}">DNS analytics →</a><a class="cdn-widget-link" href="{{ $clustersUrl }}">DNS clusters →</a></div>
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
                    <div><dt>DNS latency</dt><dd>Unavailable</dd></div>
                </dl>
                <div class="flex flex-wrap gap-4"><a class="cdn-widget-link" href="{{ $telemetryUrl }}">DNS analytics →</a><a class="cdn-widget-link" href="{{ $clustersUrl }}">DNS clusters →</a></div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
