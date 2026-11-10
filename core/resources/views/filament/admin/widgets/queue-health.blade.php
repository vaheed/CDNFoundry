<x-filament-widgets::widget>
    <x-filament::section heading="Queue lanes" description="Jobs waiting and running per lane. Horizon has the detail." icon="phosphor-queue">
        <div wire:poll.10s data-testid="queue-health">
            @if (!($state['available'] ?? false))
                <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'Queue evidence could not be loaded.'" />
            @else
                <x-ui.data-table caption="Queue lanes" fixed>
                    <x-slot:header><tr><th class="text-left">Lane</th><th class="text-right">Waiting</th><th class="text-right">Running</th><th class="text-right">Oldest</th></tr></x-slot:header>
                    @foreach ($state['items'] as $lane)
                        @php
                            $tone = $lane['status'] === 'healthy' ? (($lane['depth'] ?? 0) > 0 ? 'warning' : 'success') : ($lane['status'] === 'degraded' ? 'warning' : 'danger');
                        @endphp
                        <tr>
                            <td class="px-3 py-2" title="{{ $lane['queue'] }}"><span class="cdn-status-pill" data-tone="{{ $tone }}" aria-hidden="true"></span> {{ $lane['label'] }}</td>
                            <td class="px-3 py-2 text-right"><x-ui.status-pill :tone="$tone">{{ $lane['depth'] === null ? 'n/a' : number_format($lane['depth']) }}</x-ui.status-pill></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $lane['reserved'] === null ? 'n/a' : number_format($lane['reserved']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $lane['oldest_job_age_seconds'] === null ? 'n/a' : \Carbon\CarbonInterval::seconds($lane['oldest_job_age_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 1]) }}</td>
                        </tr>
                    @endforeach
                </x-ui.data-table>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
