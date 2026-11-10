<x-filament-widgets::widget>
    <x-filament::section heading="Finalized usage" description="Closed intervals in this range, newest first" data-testid="analytics-usage">
        @if ($csvDomain)
            <x-slot:afterHeader>
                <x-filament::button size="sm" color="gray" tag="a" icon="phosphor-download-simple" :href="route('app.analytics.usage.csv', $csvDomain)" data-testid="analytics-usage-csv">Usage CSV</x-filament::button>
            </x-slot:afterHeader>
        @endif
        @if ($rows === [])
            <x-ui.widget-state state="no_data" description="No finalized usage intervals fall in this range yet." />
        @else
            <x-ui.data-table caption="Finalized usage intervals" fixed>
                <x-slot:header><tr>@if ($multiple)<th class="text-left">Domain</th>@endif<th class="text-left">Interval</th><th class="text-right">Requests</th><th class="text-right">Transfer</th><th class="hidden text-right md:table-cell">DNS queries</th></tr></x-slot:header>
                @foreach ($rows as $row)
                    <tr>
                        @if ($multiple)<td class="px-3 py-2 font-medium" title="{{ $row['domain'] }}">{{ $row['domain'] }}</td>@endif
                        <td class="px-3 py-2 tabular-nums">{{ $this->displayTime($row['interval']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['requests'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['bytes'] }}</td>
                        <td class="hidden px-3 py-2 text-right tabular-nums md:table-cell">{{ $row['dns'] }}</td>
                    </tr>
                @endforeach
            </x-ui.data-table>
            @include('filament.domain.widgets.analytics.more', ['hasMore' => $hasMore, 'expanded' => $this->limit > 10, 'more' => 'showMore', 'fewer' => 'showFewer', 'testid' => 'analytics-usage-more'])
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
