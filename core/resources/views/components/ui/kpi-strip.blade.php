@props(['items'])

<ul class="cdn-kpis" data-testid="kpi-strip">
    @foreach ($items as $item)
        @php($tag = filled($item['url'] ?? null) ? 'a' : 'div')
        <li class="cdn-kpi" data-tone="{{ $item['tone'] ?? 'neutral' }}">
            <{{ $tag }} class="cdn-kpi-body" @if ($tag === 'a') href="{{ $item['url'] }}" @endif>
                <span class="cdn-kpi-label">{{ $item['label'] }}</span>
                <span class="cdn-kpi-value cdn-mono">{{ $item['value'] }}</span>
                @if (filled($item['note'] ?? null))<span class="cdn-kpi-note">{{ $item['note'] }}</span>@endif
            </{{ $tag }}>
        </li>
    @endforeach
</ul>
