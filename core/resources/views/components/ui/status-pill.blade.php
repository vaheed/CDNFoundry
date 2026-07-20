@props(['tone' => 'neutral'])

<span {{ $attributes->class('cdn-status-pill') }} data-tone="{{ $tone }}">
    {{ $slot }}
</span>
