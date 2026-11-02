@props(['tone' => 'neutral', 'appearance' => 'tint'])

<span {{ $attributes->class('cdn-status-pill') }} data-tone="{{ $tone }}" @if ($appearance === 'dot') data-appearance="dot" @endif>
    {{ $slot }}
</span>
