<x-filament-panels::page>
    <div class="cdn-dashboard">
        <div class="cdn-stat-grid">
            @foreach ($this->summary as $stat)
                <x-ui.stat-card :label="$stat['label']" :value="number_format($stat['value'])" :description="$stat['description']" :tone="$stat['tone']" :href="$stat['url']" />
            @endforeach
        </div>

        <div class="cdn-dashboard-columns">
            <x-filament::section heading="Recent domains" description="Your most recently changed DNS zones." icon="heroicon-o-globe-alt">
                <div class="cdn-domain-list">
                    @forelse ($this->recentDomains as $domain)
                        <a class="cdn-domain-row" href="{{ \App\Filament\Domain\Resources\Domains\DomainResource::getUrl('view', ['record' => $domain]) }}">
                            <div class="min-w-0">
                                <div class="cdn-row-title">{{ $domain->display_name ?: $domain->name }}</div>
                                <div class="cdn-row-meta">{{ $domain->name }} · {{ $domain->dns_records_count }} records · {{ $domain->proxied_records_count }} proxied</div>
                            </div>
                            <x-ui.status-pill :tone="$domain->lifecycle_state->value === 'active' ? 'success' : 'warning'">
                                {{ str($domain->lifecycle_state->value)->replace('_', ' ')->headline() }}
                            </x-ui.status-pill>
                        </a>
                    @empty
                        <x-ui.empty-state title="No assigned domains" description="Create a domain or ask an administrator to assign one to this account." />
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Start serving a domain" description="A predictable three-step authoritative DNS workflow." icon="heroicon-o-rocket-launch">
                <div class="cdn-steps">
                    <div class="cdn-step">
                        <span class="cdn-step-number">1</span>
                        <div><div class="cdn-row-title">Add the domain</div><div class="cdn-row-meta">No origin is required for DNS-only use.</div></div>
                    </div>
                    <div class="cdn-step">
                        <span class="cdn-step-number">2</span>
                        <div><div class="cdn-row-title">Delegate nameservers</div><div class="cdn-row-meta">Update the registrar, then run nameserver verification.</div></div>
                    </div>
                    <div class="cdn-step">
                        <span class="cdn-step-number">3</span>
                        <div><div class="cdn-row-title">Create DNS records</div><div class="cdn-row-meta">Choose DNS-only, Geo-DNS, or an explicitly configured proxy origin.</div></div>
                    </div>
                </div>
                <div class="mt-5">
                    <x-filament::button tag="a" icon="heroicon-o-globe-alt" :href="\App\Filament\Domain\Resources\Domains\DomainResource::getUrl()">Manage domains</x-filament::button>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
