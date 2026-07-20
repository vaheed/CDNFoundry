<x-filament-panels::page>
    @php
        $state = $this->state;
        $formatBytes = function (int|float|string|null $value): string {
            $bytes = max(0, (float) ($value ?? 0));
            $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
            $index = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

            return number_format($bytes / (1024 ** $index), $index === 0 ? 0 : 1) . ' ' . $units[$index];
        };
        $dimensions = ['bucket', 'status', 'cache_status', 'country', 'hostname', 'path', 'edge_id', 'qtype'];
        $formatMetric = function (string $key, mixed $value) use ($formatBytes): string {
            if (str_starts_with($key, 'bytes')) return $formatBytes($value);
            if (str_contains($key, 'latency')) return number_format((float) $value, 1) . ' ms';
            if (is_numeric($value)) return number_format((float) $value, str_contains($key, 'ratio') ? 2 : 0);

            return (string) $value;
        };
    @endphp

    <div class="cdn-dashboard">
        <x-filament::section heading="Domain scope" description="Every query is limited to one assigned domain. Aggregate range: last 24 hours UTC; raw previews: last hour UTC." icon="heroicon-o-shield-check">
            <div class="flex flex-wrap gap-2">
                @forelse ($this->domains as $domain)
                    <x-filament::button tag="a" :color="$state['domain']?->id === $domain->id ? 'primary' : 'gray'" :href="request()->url() . '?domain=' . $domain->id">
                        {{ $domain->display_name ?: $domain->name }}
                    </x-filament::button>
                @empty
                    <div class="cdn-empty-state w-full">No domains are assigned to this account.</div>
                @endforelse
            </div>
        </x-filament::section>

        @if ($state['domain'])
            <x-filament::section :heading="'Analytics for ' . $state['domain']->name" :description="($state['meta']['from'] ?? '') . ' through ' . ($state['meta']['to'] ?? '') . ' · UTC · bytes · milliseconds · no sampling'" icon="heroicon-o-chart-bar-square">
                <div class="flex flex-wrap gap-3">
                    <span class="cdn-status-pill" data-tone="{{ $state['available'] ? 'success' : 'danger' }}">ClickHouse {{ $state['available'] ? 'available' : 'unavailable' }}</span>
                    <span class="cdn-status-pill" data-tone="{{ ($state['meta']['partial'] ?? true) ? 'warning' : 'success' }}">{{ ($state['meta']['partial'] ?? true) ? 'Partial / provisional' : 'Finalized' }}</span>
                </div>
                @if (!$state['available'])
                    <div class="cdn-empty-state mt-4">Analytics unavailable. DNS and edge serving continue normally; finalized PostgreSQL usage remains available below.</div>
                @endif
            </x-filament::section>

            @if ($state['available'])
                @php
                    $summary = $state['summary'];
                @endphp
                <div class="cdn-stat-grid">
                    @foreach ([
                        ['label' => 'Requests', 'value' => number_format((int) ($summary['requests'] ?? 0)), 'description' => 'HTTP requests in the last 24 hours', 'tone' => 'success'],
                        ['label' => 'Bandwidth', 'value' => $formatBytes(((int) ($summary['bytes_in'] ?? 0)) + ((int) ($summary['bytes_out'] ?? 0))), 'description' => 'Inbound and outbound transfer', 'tone' => 'success'],
                        ['label' => 'Cache hit ratio', 'value' => number_format(((float) ($summary['cache_ratio'] ?? 0)) * 100, 1) . '%', 'description' => number_format((int) ($summary['cache_hits'] ?? 0)) . ' cached requests', 'tone' => 'success'],
                        ['label' => 'DNS queries', 'value' => number_format((int) ($summary['dns_queries'] ?? 0)), 'description' => 'Authoritative queries', 'tone' => 'success'],
                        ['label' => 'Origin errors', 'value' => number_format((int) ($summary['origin_errors'] ?? 0)), 'description' => 'Origin failures', 'tone' => ((int) ($summary['origin_errors'] ?? 0)) > 0 ? 'warning' : 'success'],
                        ['label' => 'Security blocks', 'value' => number_format((int) ($summary['security_blocks'] ?? 0)), 'description' => 'Protection decisions', 'tone' => 'warning'],
                    ] as $stat)
                        <div class="cdn-stat-card" data-tone="{{ $stat['tone'] }}">
                            <div class="cdn-stat-label">{{ $stat['label'] }}</div>
                            <div class="cdn-stat-value">{{ $stat['value'] }}</div>
                            <div class="cdn-stat-description">{{ $stat['description'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($state['views'] as $label => $rows)
                        <x-filament::section :heading="$label">
                            <div class="cdn-activity-list">
                                @forelse ($rows as $row)
                                    @php
                                        $dimension = collect($dimensions)->first(fn (string $key): bool => array_key_exists($key, $row));
                                        $title = $dimension ? ($row[$dimension] ?? 'Unknown') : 'Summary';
                                        if ($dimension === 'country') $title = ($row['country'] ?? 'ZZ') . ' · ' . ($row['continent'] ?? 'Unknown');
                                        if ($dimension === 'qtype') $title = ($row['qtype'] ?? 'Unknown') . ' · ' . ($row['rcode'] ?? 'Unknown');
                                        $metrics = collect($row)->except(array_filter([$dimension, 'continent', 'rcode']))->map(fn ($value, $key) => str($key)->replace('_', ' ')->headline() . ': ' . $formatMetric($key, $value))->implode(' · ');
                                    @endphp
                                    <div class="cdn-activity-row">
                                        <div class="min-w-0"><div class="cdn-row-title">{{ $title }}</div><div class="cdn-row-meta">{{ $metrics ?: 'No activity' }}</div></div>
                                    </div>
                                @empty
                                    <div class="cdn-empty-state">No data was recorded for this view.</div>
                                @endforelse
                            </div>
                        </x-filament::section>
                    @endforeach
                </div>

                <x-filament::section heading="Recent logs" description="Masked, bounded previews from the last hour. Up to 10 rows per stream." icon="heroicon-o-document-magnifying-glass">
                    <div class="grid gap-5 lg:grid-cols-2">
                        @foreach ($state['logs'] as $stream => $rows)
                            <div class="min-w-0">
                                <div class="cdn-row-title mb-2">{{ str($stream)->headline() }} logs</div>
                                <div class="cdn-activity-list">
                                    @forelse ($rows as $row)
                                        <div class="cdn-activity-row">
                                            <div class="min-w-0">
                                                <div class="cdn-row-title">{{ $row['qname'] ?? (($row['method'] ?? $row['event_type'] ?? 'event') . ' ' . ($row['path'] ?? $row['hostname'] ?? '')) }}</div>
                                                <div class="cdn-row-meta">{{ $row['occurred_at'] ?? 'Unknown time' }} · {{ $row['client_ip'] ?? 'unknown client' }} · {{ $row['rcode'] ?? $row['security_reason'] ?? $row['origin_error'] ?? ('HTTP ' . ($row['status'] ?? '—')) }}</div>
                                            </div>
                                            @if (isset($row['status']))<span class="cdn-status-pill" data-tone="{{ (int) $row['status'] >= 500 ? 'danger' : 'success' }}">{{ $row['status'] }}</span>@endif
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

            <x-filament::section heading="Finalized usage" description="Stable PostgreSQL rollups for reconciliation. Latest 20 intervals." icon="heroicon-o-document-chart-bar">
                <div class="mb-4">
                    <x-filament::button tag="a" icon="heroicon-o-arrow-down-tray" :href="route('app.analytics.usage.csv', $state['domain'])">Usage CSV export</x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500"><tr><th class="px-3 py-2">UTC interval</th><th class="px-3 py-2 text-right">Requests</th><th class="px-3 py-2 text-right">Transfer</th><th class="px-3 py-2 text-right">DNS</th><th class="px-3 py-2">State</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @forelse ($state['usage'] as $row)
                                <tr><td class="px-3 py-2 whitespace-nowrap">{{ $row['interval'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['requests']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes($row['bytes']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['dns_queries']) }}</td><td class="px-3 py-2"><span class="cdn-status-pill" data-tone="{{ $row['status'] === 'finalized' ? 'success' : 'warning' }}">{{ str($row['status'])->headline() }}</span></td></tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No finalized usage intervals are available yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
