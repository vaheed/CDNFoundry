<x-filament-widgets::widget>
    <x-filament::section heading="Data freshness" description="Source timestamps, not only browser request time. Hourly buckets identify aggregate resolution." icon="heroicon-o-signal">
        <div wire:poll.15s>
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <x-ui.status-pill :tone="match($state['state'] ?? 'unavailable') { 'fresh' => 'success', 'delayed' => 'warning', 'stale' => 'stale', default => 'unknown' }">{{ str($state['state'] ?? 'unavailable')->replace('_', ' ')->headline() }}</x-ui.status-pill>
                <x-ui.status-pill tone="info">Polling</x-ui.status-pill>
            </div>
            <dl class="cdn-freshness-list">
                @foreach ($state['sources'] ?? [] as $source)
                    <div>
                        <dt><span class="cdn-freshness-dot" data-state="{{ $source['state'] }}" aria-hidden="true"></span>{{ $source['label'] }}</dt>
                        <dd>{{ filled($source['timestamp']) ? \Carbon\CarbonImmutable::parse($source['timestamp'])->toIso8601String() : 'Unavailable' }} <span class="sr-only">State:</span><span class="cdn-freshness-state">{{ str($source['state'])->replace('_', ' ')->headline() }}</span></dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
