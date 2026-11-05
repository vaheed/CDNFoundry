@props([
    'title' => null,
    'description' => null,
    'icon' => null,
])

<div {{ $attributes->class('cdn-empty-state') }} role="status">
    @if (filled($icon))
        <x-filament::icon :icon="$icon" class="cdn-empty-icon" aria-hidden="true" />
    @endif
    @if (filled($title))
        <div class="cdn-empty-title">{{ $title }}</div>
    @endif
    <div class="cdn-empty-description">{{ $description ?? $slot }}</div>
    @if (isset($actions))
        <div class="cdn-empty-actions">{{ $actions }}</div>
    @endif
</div>
