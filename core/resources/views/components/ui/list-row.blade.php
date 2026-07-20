@props([
    'title',
    'meta' => null,
    'href' => null,
])

@php($tag = $href ? 'a' : 'div')

<{{ $tag }} {{ $attributes->class('cdn-list-row') }} @if ($href) href="{{ $href }}" @endif>
    <div class="min-w-0">
        <div class="cdn-row-title">{{ $title }}</div>
        @if (filled($meta))
            <div class="cdn-row-meta">{{ $meta }}</div>
        @endif
        {{ $slot }}
    </div>
    @if (isset($aside))
        <div class="cdn-row-aside">{{ $aside }}</div>
    @endif
</{{ $tag }}>
