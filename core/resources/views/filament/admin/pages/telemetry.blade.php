<x-filament-panels::page>
    @php
        $state = $this->state;
        $filterOptions = $this->filterOptions;
        $summary = $state['summary'];
        $formatBytes = function (int|float|string|null $value): string {
            $bytes = max(0, (float) ($value ?? 0));
            $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
            $index = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

            return number_format($bytes / (1024 ** $index), $index === 0 ? 0 : 1) . ' ' . $units[$index];
        };
    @endphp

    <div class="cdn-dashboard">
        <x-filament::section heading="Investigation context" description="Choose the time and scope for every item below. Filters are preserved in the URL; raw previews use up to the latest 24 hours." icon="heroicon-o-funnel">
            <form method="get" class="cdn-investigation-form">
                <label><span class="cdn-field-label">Time range</span><select name="range" class="cdn-filter-select"><option value="1h" @selected($this->range === '1h')>1 hour</option><option value="6h" @selected($this->range === '6h')>6 hours</option><option value="24h" @selected($this->range === '24h')>24 hours</option><option value="7d" @selected($this->range === '7d')>7 days</option></select></label>
                <label><span class="cdn-field-label">Domain</span><select name="domain" class="cdn-filter-select"><option value="">All domains</option>@foreach ($filterOptions['domains'] as $domainId => $domainName)<option value="{{ $domainId }}" @selected((string) $domainId === (string) $this->domain)>{{ $domainName }}</option>@endforeach</select></label>
                <label><span class="cdn-field-label">Edge</span><select name="edge" class="cdn-filter-select"><option value="">All edges</option>@foreach ($filterOptions['edges'] as $edgeId => $edgeName)<option value="{{ $edgeId }}" @selected((string) $edgeId === (string) $this->edge)>{{ $edgeName }}</option>@endforeach</select></label>
                <label><span class="cdn-field-label">Analytics focus</span><select name="view" class="cdn-filter-select"><option value="">All telemetry</option><option value="traffic" @selected($this->investigationView === 'traffic')>Traffic and egress</option><option value="cache" @selected($this->investigationView === 'cache')>Cache</option><option value="origin" @selected($this->investigationView === 'origin')>Origin health</option><option value="dns" @selected($this->investigationView === 'dns')>DNS</option></select></label>
                <label><span class="cdn-field-label">HTTP status</span><select name="status_family" class="cdn-filter-select"><option value="">All statuses</option><option value="4xx" @selected($this->statusFamily === '4xx')>4xx client errors</option><option value="5xx" @selected($this->statusFamily === '5xx')>5xx server errors</option></select></label>
                <x-filament::button type="submit" icon="heroicon-o-funnel">Apply filters</x-filament::button>
            </form>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-ui.status-pill tone="info">{{ $state['filters']['domain']?->name ?? 'All domains' }}</x-ui.status-pill>
                @if ($state['filters']['edge'])<x-ui.status-pill tone="warning">Edge {{ $state['filters']['edge'] }} · detailed legacy tables are not edge-scoped</x-ui.status-pill>@endif
                @if ($state['filters']['view'])<x-ui.status-pill tone="info">{{ str($state['filters']['view'])->headline() }} focus</x-ui.status-pill>@endif
                @if ($state['filters']['status_family'])<x-ui.status-pill tone="warning">HTTP {{ $state['filters']['status_family'] }} log focus</x-ui.status-pill>@endif
                @foreach ($state['filters']['errors'] as $error)<x-ui.status-pill tone="danger">{{ $error }}</x-ui.status-pill>@endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Telemetry status" :description="strtoupper($state['filters']['range']) . ' complete-hour analytics · UTC · bytes · milliseconds · no sampling. Data through ' . ($state['meta']['finalized_until'] ?? 'the finalization boundary') . ' is finalized.'" icon="heroicon-o-signal">
            <div class="flex flex-wrap gap-3">
                <x-ui.status-pill :tone="$state['available'] ? 'success' : 'danger'">ClickHouse {{ $state['available'] ? 'available' : 'unavailable' }}</x-ui.status-pill>
                <x-ui.status-pill :tone="$state['buffer']['available'] ? 'success' : 'warning'">Vector metrics {{ $state['buffer']['available'] ? 'available' : 'unavailable' }}</x-ui.status-pill>
                <x-ui.status-pill :tone="($state['meta']['partial'] ?? true) ? 'info' : 'success'">{{ ($state['meta']['partial'] ?? true) ? 'Live window included' : 'Fully finalized range' }}</x-ui.status-pill>
            </div>
            @if (in_array($state['meta']['aggregate_state'] ?? null, ['delayed', 'stale'], true))
                <x-ui.widget-state class="cdn-widget-state--compact mt-3" :state="$state['meta']['aggregate_state']" :description="'Latest complete traffic bucket: ' . ($state['meta']['aggregate_source_timestamp'] ?? 'unavailable')" />
            @endif
            @if ($state['meta']['partial'] ?? true)
                <p class="cdn-row-meta mt-3">Normal: the latest {{ $state['meta']['finalization_delay_minutes'] ?? 15 }} minutes remain provisional so this page can include current traffic. This is not a delivery warning; finalized usage is listed separately below.</p>
            @endif
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
                @livewire(\App\Filament\Admin\Widgets\TrafficOverviewChart::class, ['pageFilters' => ['range' => $state['filters']['range'], 'compare' => true, 'domain_id' => $state['filters']['domain']?->id, 'edge_id' => $state['filters']['edge']]], key('telemetry-traffic-'.$state['filters']['range'].'-'.($state['filters']['domain']?->id ?? 'all').'-'.($state['filters']['edge'] ?? 'all')))
                @livewire(\App\Filament\Admin\Widgets\ErrorLatencyChart::class, ['pageFilters' => ['range' => $state['filters']['range'], 'compare' => true, 'domain_id' => $state['filters']['domain']?->id, 'edge_id' => $state['filters']['edge']]], key('telemetry-errors-'.$state['filters']['range'].'-'.($state['filters']['domain']?->id ?? 'all').'-'.($state['filters']['edge'] ?? 'all')))
            </div>

            <div class="cdn-dashboard-columns">
                <x-filament::section heading="Global traffic" description="Hourly request and transfer totals for the last 24 hours." icon="heroicon-o-chart-bar">
                    <x-ui.data-table caption="Global hourly traffic">
                        <x-slot:header><tr><th>UTC hour</th><th class="text-right">Requests</th><th class="text-right">Transfer</th></tr></x-slot:header>
                                @forelse ($state['traffic']['items'] as $row)
                                    <tr><td class="px-3 py-2 whitespace-nowrap">{{ $row['bucket'] ?? 'Unknown' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) ($row['requests'] ?? 0)) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes(((int) ($row['bytes_in'] ?? 0)) + ((int) ($row['bytes_out'] ?? 0))) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">No traffic was recorded in this range.</td></tr>
                                @endforelse
                    </x-ui.data-table>
                    @if ($state['traffic']['has_more'] || $state['traffic']['expanded'])
                        <div class="mt-3 flex justify-end gap-2">
                            @if ($state['traffic']['has_more'])
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-down" wire:click="showMoreTraffic">Show more</x-filament::button>
                            @endif
                            @if ($state['traffic']['expanded'])
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-up" wire:click="showFewerTraffic">Show fewer</x-filament::button>
                            @endif
                        </div>
                    @endif
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

            <x-filament::section heading="Compression savings" :description="'Delivery by encoding from the latest ' . $state['meta']['raw_window_hours'] . ' hours. Identity means the response was sent without compression; Fallback explains why compression was skipped.'" icon="heroicon-o-arrows-pointing-in">
                <x-ui.data-table class="[&_.cdn-data-table]:min-w-[48rem]">
                    <x-slot:header><tr><th class="text-left">Encoding / profile</th><th class="text-left">Fallback</th><th class="text-right">Requests</th><th class="text-right">Delivered</th><th class="text-right">Saved</th><th class="text-right">Savings</th></tr></x-slot:header>
                    @forelse ($state['compression']['items'] as $row)
                        @php
                            $encoding = strtolower((string) ($row['encoding'] ?? 'identity'));
                            $encodingLabel = strtoupper($encoding);
                            $profileLabel = $encoding === 'identity'
                                ? 'No content encoding'
                                : str($row['profile'] ?? 'off')->replace('_', ' ')->headline()->toString();
                            $fallback = (string) ($row['fallback'] ?? 'none');
                            $fallbackLabel = match ($fallback) {
                                'client_identity' => 'Client requested uncompressed',
                                'cpu_pressure_identity' => 'Skipped to protect CPU capacity',
                                'emergency_disabled' => 'Skipped: compression disabled',
                                'range_identity' => 'Skipped: partial range response',
                                'none', '' => $encoding === 'identity'
                                    ? 'Normal: not eligible or below size limit'
                                    : 'Not needed',
                                default => str($fallback)->replace('_', ' ')->headline()->toString(),
                            };
                        @endphp
                        <tr>
                            <td class="px-3 py-2 text-left"><div class="font-medium">{{ $encodingLabel }}</div><div class="text-xs text-gray-500">{{ $profileLabel }}</div></td>
                            <td class="px-3 py-2 text-left">{{ $fallbackLabel }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) ($row['requests'] ?? 0)) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes($row['delivered_bytes'] ?? 0) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $formatBytes($row['bytes_saved'] ?? 0) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format(((float) ($row['savings_ratio'] ?? 0)) * 100, 1) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No compression events were recorded in the latest {{ $state['meta']['raw_window_hours'] }} hours.</td></tr>
                    @endforelse
                </x-ui.data-table>
                @if ($state['compression']['has_more'] || $state['compression']['expanded'])
                    <div class="mt-3 flex justify-end gap-2">
                        @if ($state['compression']['has_more'])
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-down" wire:click="showMoreCompression">Show more</x-filament::button>
                        @endif
                        @if ($state['compression']['expanded'])
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-up" wire:click="showFewerCompression">Show fewer</x-filament::button>
                        @endif
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Recent logs" :description="'Masked, bounded previews from the latest ' . $state['meta']['raw_window_hours'] . ' hours within this investigation. Five rows per stream until expanded.'" icon="heroicon-o-document-magnifying-glass">
                <div class="grid gap-5 xl:grid-cols-3">
                    @foreach ($state['logs'] as $stream => $logState)
                        <div class="min-w-0">
                            <div class="cdn-row-title mb-2">{{ $stream === 'requests' ? 'Edge requests' : str($stream)->headline() }}</div>
                            <div class="cdn-activity-list">
                                @forelse ($logState['items'] as $row)
                                    <div class="cdn-activity-row">
                                        <div class="min-w-0">
                                            <div class="cdn-row-title">{{ $row['hostname'] ?? $row['edge_id'] ?? ('Domain #' . ($row['domain_id'] ?? 'unknown')) }}</div>
                                            <div class="cdn-row-meta">{{ $row['occurred_at'] ?? 'Unknown time' }} · {{ $row['method'] ?? $row['event_type'] ?? 'event' }} {{ $row['path'] ?? '' }} · {{ $row['security_reason'] ?? $row['origin_error'] ?? $row['tls_error'] ?? ('HTTP ' . ($row['status'] ?? '—')) }}</div>
                                        </div>
                                        @if (isset($row['status']))<span class="cdn-status-pill" data-tone="{{ (int) $row['status'] >= 500 ? 'danger' : 'warning' }}">{{ $row['status'] }}</span>@endif
                                    </div>
                                @empty
                                    <x-ui.empty-state :title="'No ' . ($stream === 'requests' ? 'edge request' : $stream) . ' events'" :description="'Nothing was recorded in the latest ' . $state['meta']['raw_window_hours'] . ' hours.'" />
                                @endforelse
                            </div>
                            @if ($logState['has_more'] || $logState['expanded'])
                                <div class="mt-3 flex justify-center gap-2">
                                    @if ($logState['has_more'])
                                        <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-down" wire:click="showMoreLogs('{{ $stream }}')">Show more</x-filament::button>
                                    @endif
                                    @if ($logState['expanded'])
                                        <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-up" wire:click="showFewerLogs('{{ $stream }}')">Show fewer</x-filament::button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <div class="cdn-dashboard-columns">
            <x-filament::section heading="Vector buffer and delivery" description="Current collector backlog and counters since the collector last started." icon="heroicon-o-circle-stack">
                <div class="cdn-queue-list">
                    @forelse ($state['buffer']['metrics'] as $metric => $value)
                        @php
                            $problem = (str_contains($metric, 'discarded') || str_contains($metric, 'errors')) && (float) $value > 0;
                            $metricLabel = match ($metric) {
                                'vector_buffer_byte_size' => 'Buffered data',
                                'vector_buffer_events' => 'Buffered events',
                                'vector_component_discarded_events_total' => 'Discarded events since start',
                                'vector_component_errors_total' => 'Component errors since start',
                                default => str($metric)->after('vector_')->replace('_', ' ')->headline(),
                            };
                            $metricValue = $metric === 'vector_buffer_byte_size'
                                ? $formatBytes($value)
                                : number_format((float) $value, 0);
                        @endphp
                        <div class="cdn-queue-row">
                            <div class="min-w-0"><div class="cdn-row-title">{{ $metricLabel }}</div><div class="cdn-row-meta"><code>{{ $metric }}</code></div></div>
                            <span class="cdn-status-pill" data-tone="{{ $problem ? 'danger' : 'success' }}">{{ $metricValue }}</span>
                        </div>
                    @empty
                        <x-ui.empty-state title="Vector metrics unavailable" description="Serving remains independent; inspect the collector when metrics should be present." />
                    @endforelse
                </div>
                @if (($state['buffer']['metrics']['vector_component_discarded_events_total'] ?? 0) > 0 || ($state['buffer']['metrics']['vector_component_errors_total'] ?? 0) > 0)
                    <p class="cdn-row-meta mt-3">Discarded events are usually also counted as component errors. These totals are related lifetime counters and must not be added together; a zero current buffer means there is no queued delivery backlog.</p>
                    @foreach ($state['buffer']['components'] as $component => $metrics)
                        <div class="cdn-row-meta mt-1"><code>{{ $component }}</code>: {{ number_format($metrics['vector_component_discarded_events_total'] ?? 0) }} discarded · {{ number_format($metrics['vector_component_errors_total'] ?? 0) }} errors</div>
                    @endforeach
                @endif
            </x-filament::section>

            <x-filament::section heading="Finalized usage" description="Stable PostgreSQL rollups for external reconciliation. Latest 5 finalized intervals." icon="heroicon-o-document-chart-bar">
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
