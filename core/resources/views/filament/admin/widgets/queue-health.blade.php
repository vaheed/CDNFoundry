<x-filament-widgets::widget>
    <x-filament::section heading="Queue and worker health" description="Current bounded lane depth and oldest ready job. Throughput and retry counters are not exposed by the current normalized source." icon="heroicon-o-queue-list">
        <div wire:poll.10s>
            @if (!($state['available'] ?? false))
                <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'Queue evidence could not be loaded.'" />
            @else
                <div class="cdn-queue-list">
                    @foreach ($state['items'] as $lane)
                        <div class="cdn-queue-row">
                            <div class="min-w-0">
                                <div class="cdn-row-title">{{ $lane['label'] }}</div>
                                <div class="cdn-row-meta"><code>{{ $lane['queue'] }}</code> · Ready {{ $lane['ready'] === null ? 'unavailable' : number_format($lane['ready']) }} · Running {{ $lane['reserved'] === null ? 'unavailable' : number_format($lane['reserved']) }}</div>
                                <div class="cdn-row-meta">Oldest {{ $lane['oldest_job_age_seconds'] === null ? 'unavailable' : \Carbon\CarbonInterval::seconds($lane['oldest_job_age_seconds'])->cascade()->forHumans(['short' => true]) }}</div>
                            </div>
                            <x-ui.status-pill :tone="$lane['status'] === 'healthy' ? (($lane['depth'] ?? 0) > 0 ? 'warning' : 'success') : ($lane['status'] === 'degraded' ? 'warning' : 'danger')">
                                {{ $lane['depth'] === null ? 'Unavailable' : number_format($lane['depth']) }} pending
                            </x-ui.status-pill>
                        </div>
                    @endforeach
                </div>
                <p class="cdn-row-meta mt-3">Retry count and throughput: unavailable in this normalized snapshot. Horizon remains the detailed administrator drill-down.</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
