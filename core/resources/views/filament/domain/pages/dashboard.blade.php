<x-filament-panels::page>
    @php($domainsUrl = \App\Filament\Domain\Resources\Domains\DomainResource::getUrl())
    @php($traffic = $this->traffic)
    @php($attention = $this->attention)
    <div class="cdn-dashboard" data-testid="app-overview">
        @if ($this->domains->isEmpty())
            <x-ui.empty-state title="Start serving a domain" description="Add a domain, point its nameservers here, then create records.">
                <x-slot name="actions">
                    <x-filament::button tag="a" :href="$domainsUrl" data-testid="app-add-domain">Add domain</x-filament::button>
                </x-slot>
            </x-ui.empty-state>
        @else
            <section aria-labelledby="app-traffic-heading" data-testid="app-traffic">
                <h2 id="app-traffic-heading" class="cdn-section-label">Last 24 hours, all your domains</h2>
                @if ($traffic['state'] === 'ready')
                    <x-ui.kpi-strip :items="$traffic['items']" />
                @else
                    <x-ui.widget-state class="cdn-widget-state--compact" state="unavailable" title="Traffic figures are unavailable" description="Your domains keep serving; the figures return when analytics answers again." />
                @endif
            </section>

            @if ($attention !== [])
                <section class="cdn-attention" data-testid="app-attention" aria-labelledby="app-attention-heading">
                    <h2 id="app-attention-heading" class="cdn-attention-title">Needs attention <x-ui.status-pill tone="neutral">{{ count($attention) }}</x-ui.status-pill></h2>
                    <ul class="cdn-incident-list">
                        @foreach ($attention as $item)
                            <li>
                                <span class="min-w-0">
                                    <a href="{{ $item['url'] }}" class="cdn-row-title">{{ $item['domain'] }}: {{ $item['text'] }}</a>
                                    <span class="cdn-row-meta block">{{ $item['fix'] }}</span>
                                </span>
                                <x-ui.status-pill :tone="$item['tone']" appearance="dot">{{ $item['tone'] === 'danger' ? 'Act now' : 'Check' }}</x-ui.status-pill>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="cdn-panel" aria-labelledby="app-domains-heading" data-testid="app-domains">
                <div class="cdn-panel-head">
                    <h2 id="app-domains-heading" class="cdn-panel-title">Your domains</h2>
                    <div class="cdn-panel-actions">
                        <x-filament::button size="xs" color="gray" icon="phosphor-list" tag="a" :href="$domainsUrl">All domains</x-filament::button>
                    </div>
                </div>
                <x-ui.data-table caption="Your domains with their state, records, proxied hostnames, firewall level, certificate expiry, and requests in the last 24 hours">
                    <x-slot name="header">
                        <tr>
                            <th scope="col">Domain</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Records</th>
                            <th scope="col" class="text-end">Proxied</th>
                            <th scope="col">Firewall</th>
                            <th scope="col">Certificate</th>
                            <th scope="col" class="text-end">Requests (24h)</th>
                        </tr>
                    </x-slot>
                    @foreach ($this->domains as $domain)
                        @php($row = $traffic['by_domain'][$domain->id] ?? null)
                        @php($expires = $domain->activeTlsCertificate?->expires_at)
                        <tr>
                            <td class="cdn-cell-truncate"><a href="{{ \App\Filament\Domain\Resources\Domains\DomainResource::getUrl('view', ['record' => $domain]) }}" class="cdn-row-title" title="{{ $domain->name }}">{{ $domain->display_name ?: $domain->name }}</a></td>
                            <td><x-ui.status-pill :tone="$domain->lifecycle_state->value === 'active' ? 'success' : 'warning'" appearance="dot">{{ str($domain->lifecycle_state->value)->replace('_', ' ')->ucfirst() }}</x-ui.status-pill></td>
                            <td class="text-end tabular-nums">{{ number_format($domain->dns_records_count) }}</td>
                            <td class="text-end tabular-nums">{{ number_format($domain->proxied_records_count) }}</td>
                            <td>{{ ['off' => 'Off', 'monitor' => 'Observe', 'balanced' => 'Recommended', 'strict' => 'High sensitivity'][$domain->waf_profile] ?? $domain->waf_profile }}</td>
                            <td>@if ($expires)<time datetime="{{ $expires->toIso8601String() }}" title="{{ app(\App\Support\PlatformSettings::class)->formatTimestamp($expires, 'Y-m-d') }}">{{ $expires->diffForHumans() }}</time>@else<span class="cdn-dl-empty">None</span>@endif</td>
                            <td class="text-end tabular-nums">{{ $traffic['state'] === 'ready' ? number_format((int) ($row['requests'] ?? 0)) : '–' }}</td>
                        </tr>
                    @endforeach
                </x-ui.data-table>
                @if ($this->total > $this->domains->count())
                    <p class="cdn-row-meta px-4 py-2">The first {{ $this->domains->count() }} of {{ number_format($this->total) }} domains by name. <a class="cdn-widget-link" href="{{ $domainsUrl }}">See all domains</a></p>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
