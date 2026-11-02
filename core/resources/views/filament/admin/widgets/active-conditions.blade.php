<x-filament-widgets::widget>
    <div wire:poll.10s>
        @if (!($state['available'] ?? false))
            <x-ui.widget-state :state="$state['state'] ?? 'unavailable'" :description="$state['error'] ?? 'Health evidence could not be loaded.'" />
        @elseif (count($state['conditions']) > 0)
            <section class="cdn-attention" data-testid="overview-attention" aria-labelledby="needs-attention-heading">
                <h2 id="needs-attention-heading" class="cdn-attention-title">Needs attention <x-ui.status-pill>{{ count($state['conditions']) }}</x-ui.status-pill></h2>
                <div class="cdn-condition-list">
                    @foreach (array_slice($state['conditions'], 0, 4) as $condition)
                        @php($tone = match($condition['severity']) { 'critical' => 'danger', 'high' => 'warning', default => 'info' })
                        <a class="cdn-condition-row" data-tone="{{ $tone }}" href="{{ $conditionUrl($condition['key']) }}" data-testid="overview-attention-item">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.status-pill :tone="$tone" appearance="dot">{{ str($condition['severity'])->headline() }}</x-ui.status-pill>
                                    <span class="cdn-row-title">{{ $condition['summary'] }}</span>
                                </div>
                                <div class="cdn-row-meta mt-1">{{ $condition['impact'] }}</div>
                            </div>
                            <x-filament::icon icon="phosphor-caret-right" class="size-4 shrink-0 text-gray-500" aria-hidden="true" />
                        </a>
                    @endforeach
                    @if (count($state['conditions']) > 4)
                        <a class="cdn-widget-link px-3" href="{{ $conditionUrl('operations') }}" data-testid="overview-attention-more">Show all {{ count($state['conditions']) }}</a>
                    @endif
                </div>
            </section>
        @endif
    </div>
</x-filament-widgets::widget>
