<x-filament-widgets::widget>
    <section class="cdn-analytics-summary" data-testid="analytics-summary" aria-label="Analytics summary">
        <p class="cdn-row-meta" data-testid="analytics-scope">
            @if ($scope['domains'] === [])
                No assigned domains match this filter.
            @else
                <span class="block"><span class="font-medium">{{ $label }}</span>,
                {{ $this->displayTime($range['from']->toIso8601String()) }} to {{ $this->displayTime($range['to']->toIso8601String()) }}.</span>
                @if ($meta['partial'] ?? false)<span class="block">The latest {{ $meta['finalization_delay_minutes'] ?? 15 }} minutes are provisional.</span>@endif
            @endif
        </p>
        @if ($range['error'])
            <x-ui.widget-state class="cdn-widget-state--compact" state="invalid" :description="$range['error'] . ' Showing the last 24 hours.'" />
        @endif
        @if ($state === 'empty')
            <x-ui.empty-state icon="phosphor-chart-bar" title="Nothing to show" description="Analytics appear here once a domain is assigned to your account and serves traffic." />
        @elseif ($state === 'unavailable')
            <x-ui.widget-state state="unavailable" title="Analytics unavailable" description="DNS and edge serving continue normally. Try again in a few minutes; finalized usage is still listed below." />
        @else
            <x-ui.kpi-strip :items="$items" />
        @endif
    </section>
</x-filament-widgets::widget>
