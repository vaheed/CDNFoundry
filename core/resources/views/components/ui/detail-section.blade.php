@props(['title', 'rows'])

@php
    $tone = fn (string $state): string => match (true) {
        in_array($state, ['active', 'verified', 'healthy', 'validated', 'succeeded', 'normal', 'managed', 'balanced'], true) => 'success',
        in_array($state, ['failed', 'error', 'quarantine', 'strict', 'unavailable'], true) => 'danger',
        in_array($state, ['disabled', 'off', 'none'], true) => 'neutral',
        default => 'warning',
    };
@endphp

<section class="cdn-panel" aria-label="{{ $title }}">
    <h3 class="cdn-panel-title">{{ $title }}</h3>
    <dl class="cdn-dl">
        @foreach ($rows as $row)
            @php($value = $row['value'])
            @php($empty = $value === null || $value === '' || $value === [])
            <div class="cdn-dl-row">
                <dt>{{ $row['label'] }}</dt>
                <dd>
                    <div class="cdn-dl-value">
                    @if ($row['badge'])
                        <x-ui.status-pill :tone="$tone((string) $row['badge'])" appearance="dot" class="cdn-dl-badge">{{ ucfirst(str_replace('_', ' ', (string) $row['badge'])) }}</x-ui.status-pill>
                    @endif
                    @if ($empty && ! $row['badge'])
                        <span class="cdn-dl-empty">{{ $row['empty'] ?? 'None' }}</span>
                    @elseif ($empty)
                    @elseif ($row['kind'] === 'badge')
                        <x-ui.status-pill :tone="$row['tone'] ?? $tone((string) $value)" appearance="dot">{{ ucfirst(str_replace('_', ' ', (string) $value)) }}</x-ui.status-pill>
                    @elseif ($row['kind'] === 'time')
                        <time class="cdn-mono" datetime="{{ $value }}" title="{{ \Carbon\CarbonImmutable::parse($value)->diffForHumans() }}">{{ app(\App\Support\PlatformSettings::class)->formatTimestamp($value, 'Y-m-d H:i:s T') }}</time>
                    @elseif ($row['kind'] === 'chips')
                        <ul class="cdn-chips">
                            @foreach ($value as $chip)
                                <li class="cdn-chip">{{ $chip }}</li>
                            @endforeach
                        </ul>
                    @elseif ($row['kind'] === 'list')
                        @php($limit = $row['collapse'] ?? count($value))
                        <ul class="cdn-dl-list cdn-mono" x-data="{ open: false }">
                            @foreach ($value as $index => $item)
                                <li @if ($index >= $limit) x-show="open" x-cloak @endif>{{ $item }}</li>
                            @endforeach
                            @if (count($value) > $limit)
                                <li><button type="button" class="cdn-widget-link" x-on:click="open = ! open" x-text="open ? 'Show fewer' : 'Show {{ count($value) - $limit }} more'"></button></li>
                            @endif
                        </ul>
                    @else
                        <span @class(['cdn-mono' => $row['kind'] === 'mono', 'cdn-code' => $row['kind'] === 'code'])>{{ $value }}</span>
                    @endif
                    @if ($row['help'])
                        <span class="cdn-dl-help">{{ $row['help'] }}</span>
                    @endif
                    </div>
                    @if ($row['copy'] && ! $empty)
                        <button type="button" class="cdn-copy" aria-label="Copy {{ $row['label'] }}" title="Copy"
                                x-data="{ done: false }" x-on:click="navigator.clipboard.writeText(@js(is_array($value) ? implode("\n", $value) : (string) $value)); done = true; setTimeout(() => done = false, 1500)">
                            <x-filament::icon x-show="! done" icon="phosphor-copy" class="size-4" />
                            <x-filament::icon x-show="done" x-cloak icon="phosphor-check" class="size-4" />
                        </button>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</section>
