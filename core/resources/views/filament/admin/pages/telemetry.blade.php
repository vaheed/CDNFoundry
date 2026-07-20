<x-filament-panels::page>
    @php
        $state = $this->state;
        $summary = $state['summary'];
        $formatBytes = function (int|float|string|null $value): string {
            $bytes = max(0, (float) ($value ?? 0));
            $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
            $index = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

            return number_format($bytes / (1024 ** $index), $index === 0 ? 0 : 1) . ' ' . $units[$index];
        };
    @endphp

    <div class="cdn-dashboard">
        <x-filament::section heading="Telemetry status" :description="($state['meta']['from'] ?? '') . ' through ' . ($state['meta']['to'] ?? '') . ' · UTC · bytes · milliseconds · no sampling'" icon="heroicon-o-signal">
            <div class="flex flex-wrap gap-3">
                <x-ui.status-pill :tone="$state['available'] ? 'success' : 'danger'">ClickHouse {{ $state['available'] ? 'available' : 'unavailable' }}</x-ui.status-pill>
                <x-ui.status-pill :tone="$state['buffer']['available'] ? 'success' : 'warning'">Vector metrics {{ $state['buffer']['available'] ? 'available' : 'unavailable' }}</x-ui.status-pill>
                <x-ui.status-pill :tone="($state['meta']['partial'] ?? true) ? 'warning' : 'success'">{{ ($state['meta']['partial'] ?? true) ? 'Partial / provisional' : 'Finalized' }}</x-ui.status-pill>
            </div>
            @if (!$state['available'])
                <x-ui.empty-state class="mt-4" title="Analytics unavailable" description="Traffic serving is independent and remains active. Finalized PostgreSQL usage is still shown below." />
            @endif
        </x-filament::section>

        @if ($state['available'])
            <div class="cdn-stat-grid">
                @foreach ([
                    ['label' => 'Requests', 'value' => number_format((int) ($summary['requests'] ?? 0)), 'description' => 'HTTP requests in the selected range', 'tone' => 'success'],
                    ['label' => 'Bandwidth', 'value' => $formatBytes(((int) ($summary['bytes_in'] ?? 0)) + ((int) ($summary['bytes_out'] ?? 0))), 'description' => 'Inbound and outbound transfer', 'tone' => 'success'],
                    ['label' => 'Cache hit ratio', 'value' => number_format(((float) ($summary['cache_ratio'] ?? 0)) * 100, 1) . '%', 'description' => number_format((int) ($summary['cache_hits'] ?? 0)) . ' requests served from cache', 'tone' => 'success'],
                    ['label' => 'DNS queries', 'value' => number_format((int) ($summary['dns_queries'] ?? 0)), 'description' => 'Authoritative queries in the range', 'tone' => 'success'],
                    ['label' => 'Origin errors', 'value' => number_format((int) ($summary['origin_errors'] ?? 0)), 'description' => 'Origin failures requiring review', 'tone' => ((int) ($summary['origin_errors'] ?? 0)) > 0 ? 'warning' : 'success'],
                    ['label' => 'Security blocks', 'value' => number_format((int) ($summary['security_blocks'] ?? 0)), 'description' => 'Bounded protection decisions', 'tone' => 'warning'],
                ] as $stat)
                    <x-ui.stat-card :label="$stat['label']" :value="$stat['value']" :description="$stat['description']" :tone="$stat['tone']" />
                @endforeach
            </div>

            <div class="cdn-dashboard-columns">
                <x-filament::section heading="Global traffic" description="Hourly request and transfer totals for the last 24 hours." icon="heroicon-o-chart-bar">
                    <x-ui.data-table caption="Global hourly traffic">
                        <x-slot:header><tr><th>UTC hour</th><th class="text-right">Requests</th><th class="text-right">Transfer</th></tr></x-slot:header>
                                @forelse ($state['traffic'] as $row)
                                    <tr><td class="px-3 py-2 whitespace-nowrap">{{ $row['bucket'] ?? 'Unknown' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) ($row['requests'] ?? 0)) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes(((int) ($row['bytes_in'] ?? 0)) + ((int) ($row['bytes_out'] ?? 0))) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">No traffic was recorded in this range.</td></tr>
                                @endforelse
                    </x-ui.data-table>
                </x-filament::section>

                <x-filament::section heading="Global DNS" description="Authoritative queries grouped by type and response code." icon="heroicon-o-globe-alt">
                    <x-ui.data-table caption="Global DNS responses">
                        <x-slot:header><tr><th>Type</th><th>Response</th><th class="text-right">Queries</th></tr></x-slot:header>
                                @forelse ($state['dns'] as $row)
                                    <tr><td class="px-3 py-2 font-medium">{{ $row['qtype'] ?? 'Unknown' }}</td><td class="px-3 py-2">{{ $row['rcode'] ?? 'Unknown' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) ($row['queries'] ?? 0)) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">No DNS activity was recorded in this range.</td></tr>
                                @endforelse
                    </x-ui.data-table>
                </x-filament::section>
            </div>

            <x-filament::section heading="Recent logs" description="Masked, bounded previews from the last hour. Up to 10 rows per stream." icon="heroicon-o-document-magnifying-glass">
                <div class="grid gap-5 xl:grid-cols-3">
                    @foreach ($state['logs'] as $stream => $rows)
                        <div class="min-w-0">
                            <div class="cdn-row-title mb-2">{{ str($stream)->headline() }}</div>
                            <div class="cdn-activity-list">
                                @forelse ($rows as $row)
                                    <div class="cdn-activity-row">
                                        <div class="min-w-0">
                                            <div class="cdn-row-title">{{ $row['hostname'] ?? $row['edge_id'] ?? ('Domain #' . ($row['domain_id'] ?? 'unknown')) }}</div>
                                            <div class="cdn-row-meta">{{ $row['occurred_at'] ?? 'Unknown time' }} · {{ $row['method'] ?? $row['event_type'] ?? 'event' }} {{ $row['path'] ?? '' }} · {{ $row['security_reason'] ?? $row['origin_error'] ?? $row['tls_error'] ?? ('HTTP ' . ($row['status'] ?? '—')) }}</div>
                                        </div>
                                        @if (isset($row['status']))<span class="cdn-status-pill" data-tone="{{ (int) $row['status'] >= 500 ? 'danger' : 'warning' }}">{{ $row['status'] }}</span>@endif
                                    </div>
                                @empty
                                    <x-ui.empty-state :title="'No ' . $stream . ' events'" description="Nothing was recorded in the last hour." />
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <div class="cdn-dashboard-columns">
            <x-filament::section heading="Vector buffer and delivery" description="Non-zero discarded events or delivery errors require operator review." icon="heroicon-o-circle-stack">
                <div class="cdn-queue-list">
                    @forelse ($state['buffer']['metrics'] as $metric => $value)
                        @php
                            $problem = (str_contains($metric, 'discarded') || str_contains($metric, 'errors')) && (float) $value > 0;
                        @endphp
                        <div class="cdn-queue-row">
                            <div class="min-w-0"><div class="cdn-row-title">{{ str($metric)->after('vector_')->replace('_', ' ')->headline() }}</div><div class="cdn-row-meta"><code>{{ $metric }}</code></div></div>
                            <span class="cdn-status-pill" data-tone="{{ $problem ? 'danger' : 'success' }}">{{ is_numeric($value) ? number_format((float) $value, 0) : $value }}</span>
                        </div>
                    @empty
                        <x-ui.empty-state title="Vector metrics unavailable" description="Serving remains independent; inspect the collector when metrics should be present." />
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Finalized usage" description="Stable PostgreSQL rollups for external reconciliation. Latest 20 intervals." icon="heroicon-o-document-chart-bar">
                <div class="mb-4">
                    <x-filament::button tag="a" icon="heroicon-o-arrow-down-tray" :href="route('admin.telemetry.usage.csv')">Global usage CSV</x-filament::button>
                </div>
                <x-ui.data-table caption="Finalized global usage intervals">
                    <x-slot:header><tr><th>Domain / interval</th><th class="text-right">Requests</th><th class="text-right">Transfer</th><th class="text-right">DNS</th><th>State</th></tr></x-slot:header>
                            @forelse ($state['usage'] as $row)
                                <tr><td class="px-3 py-2"><div class="font-medium">{{ $row['domain'] }}</div><div class="text-xs text-gray-500">{{ $row['interval'] }}</div></td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['requests']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes($row['bytes']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['dns_queries']) }}</td><td class="px-3 py-2"><span class="cdn-status-pill" data-tone="{{ $row['status'] === 'finalized' ? 'success' : 'warning' }}">{{ str($row['status'])->headline() }}</span></td></tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No finalized usage intervals are available yet.</td></tr>
                            @endforelse
                </x-ui.data-table>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
