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
                <span class="cdn-status-pill" data-tone="{{ $state['available'] ? 'success' : 'danger' }}">ClickHouse {{ $state['available'] ? 'available' : 'unavailable' }}</span>
                <span class="cdn-status-pill" data-tone="{{ $state['buffer']['available'] ? 'success' : 'warning' }}">Vector metrics {{ $state['buffer']['available'] ? 'available' : 'unavailable' }}</span>
                <span class="cdn-status-pill" data-tone="{{ ($state['meta']['partial'] ?? true) ? 'warning' : 'success' }}">{{ ($state['meta']['partial'] ?? true) ? 'Partial / provisional' : 'Finalized' }}</span>
            </div>
            @if (!$state['available'])
                <div class="cdn-empty-state mt-4">Analytics unavailable. Traffic serving is independent and remains active. Finalized PostgreSQL usage is still shown below.</div>
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
                    <div class="cdn-stat-card" data-tone="{{ $stat['tone'] }}">
                        <div class="cdn-stat-label">{{ $stat['label'] }}</div>
                        <div class="cdn-stat-value">{{ $stat['value'] }}</div>
                        <div class="cdn-stat-description">{{ $stat['description'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="cdn-dashboard-columns">
                <x-filament::section heading="Global traffic" description="Hourly request and transfer totals for the last 24 hours." icon="heroicon-o-chart-bar">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-gray-500"><tr><th class="px-3 py-2">UTC hour</th><th class="px-3 py-2 text-right">Requests</th><th class="px-3 py-2 text-right">Transfer</th></tr></thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @forelse ($state['traffic'] as $row)
                                    <tr><td class="px-3 py-2 whitespace-nowrap">{{ $row['bucket'] ?? 'Unknown' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) ($row['requests'] ?? 0)) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes(((int) ($row['bytes_in'] ?? 0)) + ((int) ($row['bytes_out'] ?? 0))) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">No traffic was recorded in this range.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>

                <x-filament::section heading="Global DNS" description="Authoritative queries grouped by type and response code." icon="heroicon-o-globe-alt">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-gray-500"><tr><th class="px-3 py-2">Type</th><th class="px-3 py-2">Response</th><th class="px-3 py-2 text-right">Queries</th></tr></thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @forelse ($state['dns'] as $row)
                                    <tr><td class="px-3 py-2 font-medium">{{ $row['qtype'] ?? 'Unknown' }}</td><td class="px-3 py-2">{{ $row['rcode'] ?? 'Unknown' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) ($row['queries'] ?? 0)) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">No DNS activity was recorded in this range.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
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
                                    <div class="cdn-empty-state">No {{ $stream }} events in the last hour.</div>
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
                        <div class="cdn-empty-state">Vector delivery metrics are unavailable.</div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Finalized usage" description="Stable PostgreSQL rollups for external reconciliation. Latest 20 intervals." icon="heroicon-o-document-chart-bar">
                <div class="mb-4">
                    <x-filament::button tag="a" icon="heroicon-o-arrow-down-tray" :href="route('admin.telemetry.usage.csv')">Global usage CSV</x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500"><tr><th class="px-3 py-2">Domain / interval</th><th class="px-3 py-2 text-right">Requests</th><th class="px-3 py-2 text-right">Transfer</th><th class="px-3 py-2 text-right">DNS</th><th class="px-3 py-2">State</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @forelse ($state['usage'] as $row)
                                <tr><td class="px-3 py-2"><div class="font-medium">{{ $row['domain'] }}</div><div class="text-xs text-gray-500">{{ $row['interval'] }}</div></td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['requests']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes($row['bytes']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['dns_queries']) }}</td><td class="px-3 py-2"><span class="cdn-status-pill" data-tone="{{ $row['status'] === 'finalized' ? 'success' : 'warning' }}">{{ str($row['status'])->headline() }}</span></td></tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No finalized usage intervals are available yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
