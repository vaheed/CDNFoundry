@props(['items'])

<dl class="cdn-kpis" data-testid="kpi-strip">
    @foreach ($items as $item)
        @php($tag = filled($item['url'] ?? null) ? 'a' : 'div')
        <{{ $tag }} class="cdn-kpi" @if ($tag === 'a') href="{{ $item['url'] }}" @endif data-tone="{{ $item['tone'] ?? 'neutral' }}">
            <dt>{{ $item['label'] }}</dt>
            <dd class="cdn-mono">{{ $item['value'] }}</dd>
            @if (filled($item['note'] ?? null))<span class="cdn-kpi-note">{{ $item['note'] }}</span>@endif
        </{{ $tag }}>
    @endforeach
</dl>
