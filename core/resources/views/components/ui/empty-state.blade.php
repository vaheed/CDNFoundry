@props([
    'title' => null,
    'description' => null,
])

<div {{ $attributes->class('cdn-empty-state') }} role="status">
    @if (filled($title))
        <div class="cdn-empty-title">{{ $title }}</div>
    @endif
    <div class="cdn-empty-description">{{ $description ?? $slot }}</div>
    @if (isset($actions))
        <div class="cdn-empty-actions">{{ $actions }}</div>
    @endif
</div>
