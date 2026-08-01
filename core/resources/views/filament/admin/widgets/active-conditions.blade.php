<x-filament-widgets::widget>
    <x-filament::section heading="Active conditions" description="Read-only conditions detected from current health and desired-state evidence." icon="heroicon-o-exclamation-triangle">
        <div wire:poll.10s>
            @if (!($state['available'] ?? false))
                <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'Health evidence could not be loaded.'" />
            @else
                <div class="cdn-condition-list">
                    @forelse ($state['conditions'] as $condition)
                        <a class="cdn-condition-row" href="{{ $conditionUrl($condition['key']) }}">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.status-pill :tone="match($condition['severity']) { 'critical' => 'danger', 'high' => 'warning', default => 'info' }">{{ str($condition['severity'])->headline() }}</x-ui.status-pill>
                                    <span class="cdn-row-title">{{ $condition['summary'] }}</span>
                                </div>
                                <div class="cdn-row-meta mt-1">{{ $condition['impact'] }}</div>
                            </div>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="size-4 shrink-0 text-gray-400" aria-hidden="true" />
                        </a>
                    @empty
                        <x-ui.empty-state title="No active conditions" description="Every evaluated component is currently healthy." />
                    @endforelse
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
