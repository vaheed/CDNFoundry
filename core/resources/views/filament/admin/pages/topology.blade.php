@php($graph = $this->graph)
<x-filament-panels::page>
    <div wire:poll.10s x-data="{ query: '', scale: 1 }" data-testid="topology">
        <div class="cdn-topo-bar">
            <label class="sr-only" for="topology-search">Search nodes</label>
            <input id="topology-search" type="search" x-model="query" placeholder="Search nodes" class="cdn-topo-search" data-testid="topology-search">
            <ul class="cdn-topo-legend" aria-label="Legend">
                @foreach (['healthy' => 'Healthy', 'degraded' => 'Degraded', 'failed' => 'Failed', 'pending' => 'Pending', 'disabled' => 'Disabled', 'unknown' => 'Unknown'] as $status => $label)
                    <li><span class="cdn-topo-dot" data-status="{{ $status }}"></span>{{ $label }} <span class="cdn-mono">{{ $graph['counts'][$status] ?? 0 }}</span></li>
                @endforeach
            </ul>
            <span class="cdn-live" data-testid="topology-live">Polling active</span>
            <div class="cdn-topo-zoom" role="group" aria-label="Zoom">
                <x-filament::button size="sm" color="gray" x-on:click="scale = Math.max(0.5, scale - 0.25)" aria-label="Zoom out">−</x-filament::button>
                <x-filament::button size="sm" color="gray" x-on:click="scale = 1" aria-label="Reset zoom"><span x-text="Math.round(scale * 100) + '%'"></span></x-filament::button>
                <x-filament::button size="sm" color="gray" x-on:click="scale = Math.min(2, scale + 0.25)" aria-label="Zoom in">+</x-filament::button>
            </div>
        </div>

        @if ($graph['truncated'])
            <p class="cdn-setting-description">Showing the first {{ \App\Support\TopologyGraph::MAX_EDGES }} PoPs.</p>
        @endif

        <div class="cdn-topo-canvas">
            <svg role="img" aria-label="Fleet topology" viewBox="0 0 {{ $graph['width'] }} {{ $graph['height'] }}"
                 x-bind:style="`width: ${ {{ $graph['width'] }} * scale}px; height: ${ {{ $graph['height'] }} * scale}px`">
                @foreach ($graph['links'] as $link)
                    <path d="{{ $link['path'] }}" class="cdn-topo-link" data-status="{{ $link['status'] }}" fill="none" />
                @endforeach
                @foreach ($graph['nodes'] as $node)
                    <g class="cdn-topo-node" data-status="{{ $node['status'] }}" data-testid="topology-node" data-node="{{ $node['id'] }}"
                       transform="translate({{ $node['x'] }} {{ $node['y'] }})"
                       x-bind:class="query && ! @js(strtolower($node['label'].' '.$node['kind'])).includes(query.toLowerCase()) ? 'is-dimmed' : ''">
                        <title>{{ $node['label'] }}: {{ $node['status'] }}</title>
                        <rect width="{{ $graph['nodeWidth'] }}" height="{{ $node['kind'] === 'cell' ? 28 : $graph['nodeHeight'] }}" rx="6" />
                        <circle cx="14" cy="{{ $node['kind'] === 'cell' ? 14 : 14 }}" r="4" class="cdn-topo-status" />
                        <text x="26" y="{{ $node['kind'] === 'cell' ? 18 : 17 }}" class="cdn-topo-label">{{ \Illuminate\Support\Str::limit($node['label'], 22) }}</text>
                        @if ($node['kind'] !== 'cell' && filled($node['detail']))
                            <text x="26" y="32" class="cdn-topo-detail">{{ \Illuminate\Support\Str::limit($node['detail'], 26) }}</text>
                        @endif
                    </g>
                @endforeach
            </svg>
        </div>

        <ul class="sr-only">
            @foreach ($graph['nodes'] as $node)
                <li>{{ ucfirst($node['kind']) }} {{ $node['label'] }}: {{ $node['status'] }}</li>
            @endforeach
        </ul>
    </div>
</x-filament-panels::page>
