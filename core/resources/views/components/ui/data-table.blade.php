@props(['caption' => null])

<div {{ $attributes->class('cdn-table-wrap') }}>
    <table class="cdn-data-table">
        @if (filled($caption))
            <caption class="sr-only">{{ $caption }}</caption>
        @endif
        @if (isset($header))
            <thead>{{ $header }}</thead>
        @endif
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
