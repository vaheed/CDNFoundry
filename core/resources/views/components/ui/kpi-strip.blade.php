@props(['items'])

<ul class="cdn-kpis" data-testid="kpi-strip">
    @foreach ($items as $item)
        @php($tag = filled($item['url'] ?? null) ? 'a' : 'div')
        <li class="cdn-kpi" data-tone="{{ $item['tone'] ?? 'neutral' }}">
            <{{ $tag }} class="cdn-kpi-body" @if ($tag === 'a') href="{{ $item['url'] }}" @endif>
                <span class="cdn-kpi-label">{{ $item['label'] }}</span>
                <span class="cdn-kpi-value cdn-mono">{{ $item['value'] }}</span>
                @if (isset($item['meter']))
                    <span class="cdn-meter" role="meter" aria-label="{{ $item['label'] }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round(100 * $item['meter']) }}"><span style="width: {{ round(100 * max(0, min(1, $item['meter'])), 1) }}%"></span></span>
                @endif
                @if (filled($item['note'] ?? null))<span class="cdn-kpi-note">{{ $item['note'] }}</span>@endif
            </{{ $tag }}>
        </li>
    @endforeach
</ul>
