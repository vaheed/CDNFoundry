@props([
    'state' => 'unavailable',
    'title' => null,
    'description' => null,
])

@php
    $icon = match ($state) {
        'no_data' => 'heroicon-o-inbox',
        'stale', 'delayed' => 'heroicon-o-clock',
        'partial' => 'heroicon-o-information-circle',
        'invalid' => 'heroicon-o-funnel',
        default => 'heroicon-o-exclamation-triangle',
    };
    $label = $title ?? match ($state) {
        'no_data' => 'No matching data',
        'stale' => 'Data is stale',
        'delayed' => 'Data is delayed',
        'partial' => 'Provisional aggregate window',
        'invalid' => 'Invalid investigation filter',
        default => 'Data unavailable',
    };
@endphp

<div {{ $attributes->class('cdn-widget-state') }} data-state="{{ $state }}" role="status">
    <x-filament::icon :icon="$icon" class="cdn-widget-state-icon" aria-hidden="true" />
    <div>
        <div class="cdn-widget-state-title">{{ $label }}</div>
        <div class="cdn-widget-state-description">{{ $description ?? $slot }}</div>
    </div>
</div>
