<x-filament-panels::page>
    @php($state = $this->state)
    <x-filament::section heading="Telemetry status" :description="($state['meta']['from'] ?? '') . ' through ' . ($state['meta']['to'] ?? '') . ' · UTC · bytes · milliseconds · no sampling'">
        <div class="flex flex-wrap gap-3">
            <span class="cdn-status-pill" data-tone="{{ $state['available'] ? 'success' : 'danger' }}">ClickHouse {{ $state['available'] ? 'available' : 'unavailable' }}</span>
            <span class="cdn-status-pill" data-tone="{{ $state['buffer']['available'] ? 'success' : 'warning' }}">Vector metrics {{ $state['buffer']['available'] ? 'available' : 'unavailable' }}</span>
            <span class="cdn-status-pill" data-tone="{{ ($state['meta']['partial'] ?? true) ? 'warning' : 'success' }}">{{ ($state['meta']['partial'] ?? true) ? 'Partial / provisional' : 'Finalized' }}</span>
        </div>
        @if (!$state['available'])
            <div class="cdn-empty-state mt-4">Analytics unavailable. Traffic serving is independent and remains active.</div>
        @endif
    </x-filament::section>

    @if ($state['available'])
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($state['views'] as $label => $rows)
                <x-filament::section :heading="$label">
                    <pre class="max-h-72 overflow-auto text-xs">{{ json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </x-filament::section>
            @endforeach
            <x-filament::section heading="Vector buffer and delivery metrics" description="Dropped events and delivery failures require operator review.">
                <pre class="max-h-72 overflow-auto text-xs">{{ json_encode($state['buffer']['metrics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </x-filament::section>
        </div>
        <x-filament::section heading="Logs and usage" description="Global error, security, and edge events use bounded raw ranges. Usage is finalized in PostgreSQL for stable external export.">
            <div class="flex flex-wrap gap-3">
                @foreach (['errors', 'security', 'edges'] as $stream)
                    <x-filament::button tag="a" color="gray" :href="url('/api/admin/logs/' . $stream)">{{ str($stream)->headline() }}</x-filament::button>
                @endforeach
                <x-filament::button tag="a" color="gray" :href="url('/api/admin/usage')">Usage finalization</x-filament::button>
                <x-filament::button tag="a" color="gray" :href="url('/api/admin/usage/export?format=csv')">Global usage CSV</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
