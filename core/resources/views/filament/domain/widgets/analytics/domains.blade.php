<x-filament-widgets::widget>
    @if ($visible)
        <x-filament::section heading="Domains" description="Busiest first" data-testid="analytics-domains">
            @if ($state === 'unavailable')
                <x-ui.widget-state state="unavailable" description="The domain breakdown query failed. Serving is not affected." />
            @elseif ($state === 'no_data')
                <x-ui.widget-state state="no_data" description="None of these domains served traffic in this range." />
            @else
                <x-ui.data-table caption="Traffic by domain" fixed>
                    <x-slot:header><tr><th class="text-left">Domain</th><th class="text-right">Requests</th><th class="text-right">Bandwidth</th><th class="hidden text-right md:table-cell">Cache hits</th><th class="hidden text-right md:table-cell">Origin errors</th></tr></x-slot:header>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2"><a class="cdn-table-link" href="{{ $row['url'] }}" wire:navigate>{{ $row['name'] }}</a></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['requests'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['bandwidth'] }}</td>
                            <td class="hidden px-3 py-2 text-right tabular-nums md:table-cell">{{ $row['cache'] }}</td>
                            <td class="hidden px-3 py-2 text-right tabular-nums md:table-cell">{{ $row['errors'] }}</td>
                        </tr>
                    @endforeach
                </x-ui.data-table>
                @include('filament.domain.widgets.analytics.more', ['hasMore' => $hasMore, 'expanded' => $this->limit > 10, 'more' => 'showMore', 'fewer' => 'showFewer', 'testid' => 'analytics-domains-more'])
            @endif
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
