<x-filament-widgets::widget>
    <div data-testid="overview-recent"><x-filament::section heading="Recent operations">
        <x-slot name="afterHeader">
            <div class="flex gap-3"><a class="cdn-widget-link" href="{{ $operationsUrl }}" data-testid="overview-operations-link">Operations</a><a class="cdn-widget-link" href="{{ $auditsUrl }}" data-testid="overview-audit-link">Audit log</a></div>
        </x-slot>
        <div wire:poll.10s>
            @if (!($state['available'] ?? false))
                <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'Timeline evidence could not be loaded.'" />
            @else
                <ol class="cdn-timeline">
                    @forelse (array_slice($state['items'], 0, 5) as $item)
                        <li class="cdn-timeline-item">
                            @php($tone = $item['status'] === 'failed' ? 'danger' : (in_array($item['status'], ['pending', 'running'], true) ? 'warning' : 'success'))
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="cdn-row-title">{{ str($item['type'])->replace(['.', '_'], ' ')->headline() }}</span>
                                    <x-ui.status-pill :tone="$tone" appearance="dot">{{ str($item['status'])->headline() }}</x-ui.status-pill>
                                </div>
                                <div class="cdn-row-meta flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="min-w-0 truncate">{{ $item['target'] }}</span>
                                    <span>{{ $item['actor'] }}</span>
                                    <time class="cdn-mono" title="{{ $this->dashboardTimestamp($item['occurred_at']) }}" datetime="{{ $item['occurred_at'] }}">{{ filled($item['occurred_at']) ? \Carbon\CarbonImmutable::parse($item['occurred_at'])->diffForHumans() : 'Unknown time' }}</time>
                                    @if($item['duration_seconds'] !== null)<span class="cdn-chip">{{ \Carbon\CarbonInterval::seconds($item['duration_seconds'])->cascade()->forHumans(['short' => true]) }}</span>@endif
                                </div>
                            </div>
                        </li>
                    @empty
                        <x-ui.empty-state title="No recent changes" description="Operations and audited changes will appear here." />
                    @endforelse
                </ol>
            @endif
        </div>
    </x-filament::section></div>
</x-filament-widgets::widget>
