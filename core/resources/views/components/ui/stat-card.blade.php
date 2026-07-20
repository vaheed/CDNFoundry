@props([
    'label',
    'value',
    'description' => null,
    'tone' => 'neutral',
    'href' => null,
])

@php($tag = $href ? 'a' : 'div')

<{{ $tag }}
    {{ $attributes->class('cdn-stat-card') }}
    data-tone="{{ $tone }}"
    @if ($href) href="{{ $href }}" aria-label="Open {{ $label }}" @endif
>
    <div class="cdn-stat-label">{{ $label }}</div>
    <div class="cdn-stat-value">{{ $value }}</div>
    @if (filled($description))
        <div class="cdn-stat-description">{{ $description }}</div>
    @endif
</{{ $tag }}>
