<x-filament-panels::page>
    <div class="cdn-dashboard">
        <div class="cdn-stat-grid">
            @foreach ($this->summary as $stat)
                <a class="cdn-stat-card" data-tone="{{ $stat['tone'] }}" href="{{ $stat['url'] }}" aria-label="Open {{ $stat['label'] }}">
                    <div class="cdn-stat-label">{{ $stat['label'] }}</div>
                    <div class="cdn-stat-value">{{ number_format($stat['value']) }}</div>
                    <div class="cdn-stat-description">{{ $stat['description'] }}</div>
                </a>
            @endforeach
        </div>

        <div class="cdn-dashboard-columns">
            <x-filament::section heading="Queue lanes" description="Current Redis backlog by bounded worker lane." icon="heroicon-o-queue-list">
                <div class="cdn-queue-list">
                    @foreach ($this->queueState as $lane)
                        <div class="cdn-queue-row">
                            <div>
                                <div class="cdn-row-title">{{ $lane['label'] }}</div>
                                <div class="cdn-row-meta"><code>{{ $lane['key'] }}</code> · {{ $lane['oldest'] }}</div>
                            </div>
                            <span class="cdn-status-pill" data-tone="{{ $lane['tone'] }}">
                                {{ $lane['depth'] === null ? 'Unavailable' : number_format($lane['depth']) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section heading="Recent audit activity" description="Latest security and configuration changes." icon="heroicon-o-clipboard-document-list">
                <div class="cdn-activity-list">
                    @forelse ($this->recentAudits as $entry)
                        <div class="cdn-activity-row">
                            <div class="min-w-0">
                                <div class="cdn-row-title">{{ str($entry->action)->replace(['.', '_'], ' ')->headline() }}</div>
                                <div class="cdn-row-meta">{{ $entry->actor?->email ?? 'System' }} · {{ $entry->created_at?->diffForHumans() }}</div>
                            </div>
                            <span class="cdn-status-pill">#{{ $entry->id }}</span>
                        </div>
                    @empty
                        <div class="cdn-empty-state">No audit activity has been recorded yet.</div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Common tasks" description="Direct links to the control-plane workflows used most often." icon="heroicon-o-bolt">
            <div class="flex flex-wrap gap-3">
                @foreach ($this->quickLinks as $link)
                    <x-filament::button tag="a" color="gray" :icon="$link['icon']" :href="$link['url']">
                        {{ $link['label'] }}
                    </x-filament::button>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
