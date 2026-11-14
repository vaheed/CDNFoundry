@props(['caption' => null, 'fixed' => false, 'auto' => false])

<div {{ $attributes->class(['cdn-table-wrap', 'cdn-table-wrap--fit' => $fixed]) }}>
    <table @class(['cdn-data-table', 'cdn-data-table--fixed' => $fixed, 'cdn-data-table--auto' => $auto])>
        @if (filled($caption))
            <caption class="sr-only">{{ $caption }}</caption>
        @endif
        @if (isset($header))
            <thead>{{ $header }}</thead>
        @endif
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
