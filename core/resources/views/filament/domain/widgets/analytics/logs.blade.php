<x-filament-widgets::widget>
    <x-filament::section heading="Recent events" description="Last 24 hours of the range, client addresses masked" data-testid="analytics-logs">
        <x-filament::tabs label="Event stream" class="cdn-widget-tabs">
            @foreach ($streams as $key => $label)
                <x-filament::tabs.item :active="$active === $key" wire:click="selectStream('{{ $key }}')" data-testid="analytics-logs-{{ $key }}">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <div class="mt-3" wire:loading.class="opacity-60" wire:target="selectStream,showMore,showFewer">
            @if ($state === 'unavailable')
                <x-ui.widget-state state="unavailable" description="Recent events could not be loaded. Serving is not affected." />
            @elseif ($state === 'expired')
                <x-ui.widget-state state="no_data" description="Event details are kept for 7 days. Choose a range that ends within the last week." />
            @elseif ($state === 'no_data')
                <x-ui.widget-state state="no_data" description="No {{ strtolower($streams[$active]) }} events were recorded in this window." />
            @elseif ($active === 'dns')
                <x-ui.data-table caption="Recent DNS queries" fixed>
                    <x-slot:header><tr><th class="text-left">Name</th><th class="text-left">Type</th><th class="hidden text-left md:table-cell">Client</th><th class="text-right">Time</th></tr></x-slot:header>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2" title="{{ $row['qname'] ?? '' }}"><span class="font-medium">{{ $row['qname'] ?? 'Unknown' }}</span><div class="cdn-row-meta">{{ str($row['rcode'] ?? 'unknown')->lower()->ucfirst() }}</div></td>
                            <td class="px-3 py-2">{{ $row['qtype'] ?? '' }}</td>
                            <td class="hidden px-3 py-2 md:table-cell" title="{{ $row['client_ip'] ?? '' }}"><span class="cdn-code">{{ $row['client_ip'] ?? '' }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums" title="{{ $this->displayTime($row['occurred_at'] ?? null, 'Y-m-d H:i:s') }}">{{ $this->displayTime($row['occurred_at'] ?? null, 'H:i:s') }}</td>
                        </tr>
                    @endforeach
                </x-ui.data-table>
            @else
                <x-ui.data-table caption="Recent {{ strtolower($streams[$active]) }} events" fixed>
                    <x-slot:header><tr><th class="text-left">Request</th><th class="text-right">Status</th><th class="hidden text-left md:table-cell">Client</th><th class="text-right">Time</th></tr></x-slot:header>
                    @foreach ($rows as $row)
                        @php($request = ($row['hostname'] ?? '') . ($row['path'] ?? ''))
                        <tr>
                            <td class="px-3 py-2" title="{{ $request }}">
                                <span class="font-medium">{{ $row['method'] ?? '' }}</span> <span class="cdn-code">{{ $request }}</span>
                                @if ($reason = $this->reason($row))<div class="cdn-row-meta" title="{{ $reason }}">{{ $reason }}</div>@endif
                            </td>
                            <td class="px-3 py-2 text-right"><x-ui.status-pill :tone="(int) ($row['status'] ?? 0) >= 500 ? 'danger' : ((int) ($row['status'] ?? 0) >= 400 ? 'warning' : 'success')">{{ $row['status'] ?? '' }}</x-ui.status-pill></td>
                            <td class="hidden px-3 py-2 md:table-cell" title="{{ $row['client_ip'] ?? '' }}"><span class="cdn-code">{{ $row['client_ip'] ?? '' }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums" title="{{ $this->displayTime($row['occurred_at'] ?? null, 'Y-m-d H:i:s') }}">{{ $this->displayTime($row['occurred_at'] ?? null, 'H:i:s') }}</td>
                        </tr>
                    @endforeach
                </x-ui.data-table>
            @endif
            @if (in_array($state, ['ready'], true))
                @include('filament.domain.widgets.analytics.more', ['hasMore' => $hasMore, 'expanded' => $this->limit > 10, 'more' => 'showMore', 'fewer' => 'showFewer', 'testid' => 'analytics-logs-more'])
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
