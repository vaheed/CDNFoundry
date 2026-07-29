<x-filament-panels::page>
    <div class="cdn-dashboard" wire:poll.10s>
        <div class="cdn-stat-grid">
            @foreach ($this->summary as $stat)
                <x-ui.stat-card :label="$stat['label']" :value="number_format($stat['value'])" :description="$stat['description']" :tone="$stat['tone']" :href="$stat['url']" />
            @endforeach
        </div>

        <div class="cdn-dashboard-columns">
            <x-filament::section heading="Component health" description="Bounded dependency and reconciliation checks. Prometheus and Alertmanager own alerting." icon="heroicon-o-heart">
                <div class="cdn-queue-list">
                    @foreach ($this->componentState as $healthState)
                        <div class="cdn-queue-row">
                            <div class="min-w-0">
                                <div class="cdn-row-title">{{ $healthState['name'] }}</div>
                                <div class="cdn-row-meta">{{ $healthState['summary'] }}</div>
                                @if ($healthState['status'] !== 'healthy')
                                    <div class="cdn-row-meta mt-1"><strong>How to fix:</strong> {{ $healthState['guidance'] }}</div>
                                @endif
                                <div class="cdn-row-meta mt-1">Checked {{ $healthState['checked_at'] }}</div>
                            </div>
                            <x-ui.status-pill :tone="$healthState['status'] === 'healthy' ? 'success' : ($healthState['status'] === 'degraded' ? 'warning' : 'danger')">{{ str($healthState['status'])->headline() }}</x-ui.status-pill>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <div class="grid min-w-0 gap-6">
                <x-filament::section heading="Queue lanes" description="Current Redis backlog by bounded worker lane." icon="heroicon-o-queue-list">
                    <div class="cdn-queue-list">
                        @foreach ($this->queueState as $lane)
                            <div class="cdn-queue-row">
                                <div>
                                    <div class="cdn-row-title">{{ $lane['label'] }}</div>
                                    <div class="cdn-row-meta">
                                        <code>{{ $lane['key'] }}</code> · {{ $lane['oldest'] }}
                                        @if ($lane['depth'] !== null)
                                            · Ready {{ number_format($lane['ready']) }} · Reserved {{ number_format($lane['reserved']) }} · Delayed {{ number_format($lane['delayed']) }}
                                        @endif
                                    </div>
                                </div>
                                <x-ui.status-pill :tone="$lane['tone']">
                                    {{ $lane['depth'] === null ? 'Unavailable' : number_format($lane['depth']) }}
                                </x-ui.status-pill>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>

                <x-filament::section heading="Recent audit activity" description="Latest security and configuration changes." icon="heroicon-o-clipboard-document-list">
                    <x-ui.data-table caption="Recent audit activity">
                        <x-slot:header><tr><th>Action</th><th>Actor</th><th>Time</th><th class="text-right">ID</th></tr></x-slot:header>
                        @forelse ($this->recentAudits as $entry)
                            <tr>
                                <td class="font-medium">{{ str($entry->action)->replace(['.', '_'], ' ')->headline() }}</td>
                                <td>{{ $entry->actor?->email ?? 'System' }}</td>
                                <td class="whitespace-nowrap">{{ $entry->created_at?->diffForHumans() }}</td>
                                <td class="text-right tabular-nums">#{{ $entry->id }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-ui.empty-state title="No audit activity" description="Configuration and security changes will appear here." /></td></tr>
                        @endforelse
                    </x-ui.data-table>
                </x-filament::section>
            </div>
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
