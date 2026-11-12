@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $heading = $this->getHeading();
    $type = $this->getType();
    $maxHeight = $this->getMaxHeight();
    $series = $this->getSeries();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :heading="$heading" data-testid="analytics-chart">
        @if ($series['state'] === 'unavailable')
            <x-ui.widget-state state="unavailable" description="The chart query failed. Serving is not affected." />
        @elseif ($series['state'] === 'no_data')
            <x-ui.widget-state state="no_data" description="No traffic was recorded for these domains in this range." />
        @else
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                data-chart-type="{{ $type }}"
                x-data="chart({ cachedData: @js($this->getCachedData()), options: @js($this->getOptions()), type: @js($type) })"
                {{ (new ComponentAttributeBag)->color(ChartWidgetComponent::class, $this->getColor())->class(['fi-wi-chart-canvas-ctn', 'fi-wi-chart-canvas-ctn-no-aspect-ratio']) }}
            >
                <canvas x-ref="canvas" aria-label="{{ $heading }} chart" role="img" style="width: 100%; max-height: {{ $maxHeight }}"></canvas>
                <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
