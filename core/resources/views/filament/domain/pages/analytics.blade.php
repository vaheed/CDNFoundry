<x-filament-panels::page>
    @php
        $state = $this->state;
        $display = app(\App\Support\PlatformSettings::class);
        $formatBytes = function (int|float|string|null $value): string {
            $bytes = max(0, (float) ($value ?? 0));
            $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
            $index = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

            return number_format($bytes / (1024 ** $index), $index === 0 ? 0 : 1) . ' ' . $units[$index];
        };
        $dimensions = ['bucket', 'status', 'cache_status', 'country', 'hostname', 'path', 'edge_id', 'encoding', 'qtype'];
        $formatMetric = function (string $key, mixed $value) use ($formatBytes, $display): string {
            if ($key === 'bucket') return $display->formatTimestamp($value);
            if (str_starts_with($key, 'bytes')) return $formatBytes($value);
            if (str_contains($key, 'latency')) return number_format((float) $value, 1) . ' ms';
            if (is_numeric($value)) return number_format((float) $value, str_contains($key, 'ratio') ? 2 : 0);

            return (string) $value;
        };
    @endphp

    <div class="cdn-dashboard">
        @unless ($state['domain'])
            <x-ui.empty-state title="Select a domain" description="Use Select domain above to load its analytics." />
        @endunless

        @if ($state['domain'])
            <x-filament::section :heading="'Analytics for ' . $state['domain']->name" :description="$display->formatTimestamp($state['meta']['from'] ?? null) . ' to ' . $display->formatTimestamp($state['meta']['to'] ?? null)" icon="phosphor-chart-bar">
                <div class="flex flex-wrap gap-3">
                    <x-ui.status-pill :tone="$state['available'] ? 'success' : 'danger'">ClickHouse {{ $state['available'] ? 'available' : 'unavailable' }}</x-ui.status-pill>
                    <x-ui.status-pill :tone="($state['meta']['partial'] ?? true) ? 'info' : 'success'">{{ ($state['meta']['partial'] ?? true) ? 'Live window included' : 'Fully finalized range' }}</x-ui.status-pill>
                </div>
                @if ($state['meta']['partial'] ?? true)
                    <p class="cdn-row-meta mt-3">The latest {{ $state['meta']['finalization_delay_minutes'] ?? 15 }} minutes are provisional.</p>
                @endif
                @if (!$state['available'])
                    <x-ui.empty-state class="mt-4" title="Analytics unavailable" description="DNS and edge serving continue normally; finalized PostgreSQL usage remains available below." />
                @endif
            </x-filament::section>

            @if ($state['available'])
                @php
                    $summary = $state['summary'];
                @endphp
                <div class="cdn-stat-grid">
                    @foreach ([
                        ['label' => 'Requests', 'value' => number_format((int) ($summary['requests'] ?? 0)), 'description' => null, 'tone' => 'success'],
                        ['label' => 'Bandwidth', 'value' => $formatBytes(((int) ($summary['bytes_in'] ?? 0)) + ((int) ($summary['bytes_out'] ?? 0))), 'description' => null, 'tone' => 'success'],
                        ['label' => 'Cache hit ratio', 'value' => number_format(((float) ($summary['cache_ratio'] ?? 0)) * 100, 1) . '%', 'description' => number_format((int) ($summary['cache_hits'] ?? 0)) . ' cached', 'tone' => 'success'],
                        ['label' => 'DNS queries', 'value' => number_format((int) ($summary['dns_queries'] ?? 0)), 'description' => null, 'tone' => 'success'],
                        ['label' => 'Origin errors', 'value' => number_format((int) ($summary['origin_errors'] ?? 0)), 'description' => null, 'tone' => ((int) ($summary['origin_errors'] ?? 0)) > 0 ? 'warning' : 'success'],
                        ['label' => 'Security blocks', 'value' => number_format((int) ($summary['security_blocks'] ?? 0)), 'description' => null, 'tone' => 'warning'],
                    ] as $stat)
                        <x-ui.stat-card :label="$stat['label']" :value="$stat['value']" :description="$stat['description']" :tone="$stat['tone']" />
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
                                        if ($dimension === 'bucket') $title = $display->formatTimestamp($row['bucket'] ?? null);
                                        if ($dimension === 'country') $title = ($row['country'] ?? 'ZZ') . ' · ' . ($row['continent'] ?? 'Unknown');
                                        if ($dimension === 'qtype') $title = ($row['qtype'] ?? 'Unknown') . ' · ' . ($row['rcode'] ?? 'Unknown');
                                        if ($dimension === 'encoding') $title = strtoupper($row['encoding'] ?? 'identity') . ' · ' . str($row['profile'] ?? 'off')->replace('_', ' ')->headline();
                                        $metrics = collect($row)->except(array_filter([$dimension, 'continent', 'rcode']))->map(fn ($value, $key) => ['label' => str($key)->replace('_', ' ')->headline()->toString(), 'value' => $formatMetric($key, $value)]);
                                    @endphp
                                    <div class="cdn-activity-row">
                                        <div class="min-w-0"><div class="cdn-row-title">{{ $title }}</div>@forelse ($metrics as $metric)<div class="cdn-row-meta">{{ $metric['label'] }} <span class="tabular-nums">{{ $metric['value'] }}</span></div>@empty<div class="cdn-row-meta">No activity</div>@endforelse</div>
                                    </div>
                                @empty
                                    <x-ui.empty-state title="No data" description="No activity was recorded for this view and time range." />
                                @endforelse
                            </div>
                        </x-filament::section>
                    @endforeach
                </div>

                <x-filament::section heading="Recent logs" description="Masked previews, last hour" icon="phosphor-file-magnifying-glass">
                    <div class="grid gap-5 lg:grid-cols-2">
                        @foreach ($state['logs'] as $stream => $rows)
                            <div class="min-w-0">
                                <div class="cdn-row-title mb-2">{{ str($stream)->headline() }} logs</div>
                                <div class="cdn-activity-list">
                                    @forelse ($rows as $row)
                                        <div class="cdn-activity-row">
                                            <div class="min-w-0">
                                                <div class="cdn-row-title">{{ $row['qname'] ?? (($row['method'] ?? $row['event_type'] ?? 'event') . ' ' . ($row['path'] ?? $row['hostname'] ?? '')) }}</div>
                                                <div class="cdn-row-meta">{{ $display->formatTimestamp($row['occurred_at'] ?? null) }}</div>
                                                <div class="cdn-row-meta"><span class="cdn-mono">{{ $row['client_ip'] ?? 'unknown client' }}</span></div>
                                                @if (isset($row['rcode']) || isset($row['security_reason']) || isset($row['origin_error']))<div class="cdn-row-meta">{{ $row['rcode'] ?? $row['security_reason'] ?? $row['origin_error'] }}</div>@endif
                                            </div>
                                            @if (isset($row['status']))<span class="cdn-status-pill" data-tone="{{ (int) $row['status'] >= 500 ? 'danger' : 'success' }}">{{ $row['status'] }}</span>@endif
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

            <x-filament::section heading="Finalized usage" description="Latest 5 intervals" icon="phosphor-receipt">
                <div class="mb-4">
                    <x-filament::button tag="a" icon="phosphor-download-simple" :href="route('app.analytics.usage.csv', $state['domain'])">Usage CSV export</x-filament::button>
                </div>
                <x-ui.data-table caption="Finalized domain usage intervals">
                    <x-slot:header><tr><th>Interval ({{ $display->displayTimezone() }})</th><th class="text-right">Requests</th><th class="text-right">Transfer</th><th class="text-right">DNS</th><th>State</th></tr></x-slot:header>
                            @forelse ($state['usage'] as $row)
                                <tr><td class="px-3 py-2 whitespace-nowrap">{{ $display->formatTimestamp($row['interval']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['requests']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes($row['bytes']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['dns_queries']) }}</td><td class="px-3 py-2"><span class="cdn-status-pill" data-tone="{{ $row['status'] === 'finalized' ? 'success' : 'warning' }}">{{ str($row['status'])->headline() }}</span></td></tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No finalized usage intervals are available yet.</td></tr>
                            @endforelse
                </x-ui.data-table>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
