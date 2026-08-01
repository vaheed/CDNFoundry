@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $type = $this->getType();
    $maxHeight = $this->getMaxHeight();
    $hasMaxHeight = filled($maxHeight) && $maxHeight !== '100%';
    $displayState = $this->getDisplayState();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">
        <div @if ($pollingInterval = $this->getPollingInterval()) wire:poll.{{ $pollingInterval }}="updateChartData" @endif>
            @if (!($displayState['available'] ?? false) || in_array($displayState['state'] ?? null, ['invalid', 'unavailable'], true))
                <x-ui.widget-state :state="$displayState['state'] ?? 'unavailable'" :description="$displayState['error'] ?? 'The chart query failed.'" />
            @elseif (($displayState['state'] ?? null) === 'no_data')
                <x-ui.widget-state state="no_data" description="The query succeeded, but no hourly aggregates match this investigation context." />
            @else
                <div
                    x-load
                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                    wire:ignore
                    data-chart-type="{{ $type }}"
                    x-data="chart({ cachedData: @js($this->getCachedData()), options: @js($this->getOptions()), type: @js($type) })"
                    {{
                        (new ComponentAttributeBag)
                            ->color(ChartWidgetComponent::class, $color)
                            ->class(['fi-wi-chart-canvas-ctn', 'fi-wi-chart-canvas-ctn-no-aspect-ratio' => $hasMaxHeight])
                    }}
                >
                    <canvas x-ref="canvas" aria-label="{{ $heading }} chart" role="img" @style(['width: 100%', 'height: 100%; max-height: 100%' => ! $hasMaxHeight, ('max-height: ' . e($maxHeight)) => $hasMaxHeight])></canvas>
                    <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                    <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                    <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                    <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                </div>
                <details class="cdn-chart-summary">
                    <summary>Accessible data summary</summary>
                    <p>{{ count($displayState['current'] ?? []) }} hourly points. Source through {{ $displayState['source_timestamp'] ?? 'an unavailable timestamp' }}. Use Traffic and telemetry for tabular detail.</p>
                </details>
                <a class="cdn-widget-link" href="{{ $this->getDrilldownUrl() }}">Open detailed analytics <span aria-hidden="true">→</span></a>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
