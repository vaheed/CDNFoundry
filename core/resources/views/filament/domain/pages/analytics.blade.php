<x-filament-panels::page>
    @php($state = $this->state)
    <x-filament::section heading="Domain scope" description="Every query is limited to one assigned domain. Aggregate range: last 24 hours UTC; raw previews: last hour UTC.">
        <div class="flex flex-wrap gap-2">
            @forelse ($this->domains as $domain)
                <x-filament::button tag="a" :color="$state['domain']?->id === $domain->id ? 'primary' : 'gray'" :href="request()->url() . '?domain=' . $domain->id">
                    {{ $domain->display_name ?: $domain->name }}
                </x-filament::button>
            @empty
                <div class="cdn-empty-state">No domains are assigned to this account.</div>
            @endforelse
        </div>
    </x-filament::section>

    @if ($state['domain'])
        <x-filament::section :heading="'Analytics for ' . $state['domain']->name" :description="($state['meta']['from'] ?? '') . ' through ' . ($state['meta']['to'] ?? '') . ' · UTC · bytes · milliseconds · no sampling'">
            @if (!$state['available'])
                <div class="cdn-empty-state">Analytics unavailable. DNS and edge serving continue normally; retry after ClickHouse or Vector recovers.</div>
            @else
                <div class="mb-4"><span class="cdn-status-pill" data-tone="{{ $state['meta']['partial'] ? 'warning' : 'success' }}">{{ $state['meta']['partial'] ? 'Partial / provisional' : 'Finalized' }}</span></div>
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($state['views'] as $label => $rows)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <div class="cdn-row-title">{{ $label }}</div>
                            <pre class="mt-3 max-h-64 overflow-auto text-xs">{{ json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 flex flex-wrap gap-3">
                    @foreach (['requests', 'dns', 'errors', 'security'] as $stream)
                        <x-filament::button tag="a" color="gray" :href="url('/api/domains/' . $state['domain']->id . '/logs/' . $stream)">{{ str($stream)->headline() }} logs</x-filament::button>
                    @endforeach
                    <x-filament::button tag="a" color="gray" :href="url('/api/domains/' . $state['domain']->id . '/usage/export?format=csv')">Usage CSV export</x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
