<x-filament-widgets::widget>
    <x-filament::section heading="Operations timeline" description="Deployments, reconciliations, purges, certificate work, and audited configuration changes before the current condition." icon="heroicon-o-clock">
        <x-slot name="afterHeader">
            <div class="flex gap-3"><a class="cdn-widget-link" href="{{ $operationsUrl }}">Operations</a><a class="cdn-widget-link" href="{{ $auditsUrl }}">Audit log</a></div>
        </x-slot>
        <div wire:poll.10s>
            @if (!($state['available'] ?? false))
                <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'Timeline evidence could not be loaded.'" />
            @else
                <ol class="cdn-timeline">
                    @forelse ($state['items'] as $item)
                        <li class="cdn-timeline-item">
                            <span class="cdn-timeline-marker" data-tone="{{ $item['status'] === 'failed' ? 'danger' : (in_array($item['status'], ['pending', 'running'], true) ? 'warning' : 'success') }}" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="cdn-row-title">{{ str($item['type'])->replace(['.', '_'], ' ')->headline() }}</span>
                                    <time class="cdn-row-meta" datetime="{{ $item['occurred_at'] }}">{{ filled($item['occurred_at']) ? \Carbon\CarbonImmutable::parse($item['occurred_at'])->diffForHumans() : 'Unknown time' }}</time>
                                </div>
                                <div class="cdn-row-meta">{{ $item['target'] }} · {{ $item['actor'] }} · {{ str($item['status'])->headline() }}@if($item['duration_seconds'] !== null) · {{ \Carbon\CarbonInterval::seconds($item['duration_seconds'])->cascade()->forHumans(['short' => true]) }}@endif</div>
                            </div>
                        </li>
                    @empty
                        <x-ui.empty-state title="No recent changes" description="Operations and audited changes will appear here." />
                    @endforelse
                </ol>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
