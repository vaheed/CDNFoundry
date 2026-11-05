<x-filament-panels::page>
    @php($domainsUrl = \App\Filament\Domain\Resources\Domains\DomainResource::getUrl())
    <div class="cdn-dashboard" data-testid="app-overview">
        <x-ui.kpi-strip :items="collect($this->summary)->map(fn (array $stat): array => ['label' => $stat['label'], 'value' => number_format($stat['value']), 'tone' => $stat['tone'] === 'primary' ? 'neutral' : $stat['tone'], 'url' => $stat['url']])->all()" />

        @if ($this->recentDomains->isEmpty())
            <x-ui.empty-state title="Start serving a domain" description="Add a domain, point its nameservers here, then create records.">
                <x-slot name="actions">
                    <x-filament::button tag="a" :href="$domainsUrl" data-testid="app-add-domain">Add domain</x-filament::button>
                </x-slot>
            </x-ui.empty-state>
        @else
            <section class="cdn-panel" aria-label="Recent domains">
                <h2 class="cdn-panel-title">Recent domains</h2>
                <ul>
                    @foreach ($this->recentDomains as $domain)
                        <li class="cdn-dl-row">
                            <a href="{{ \App\Filament\Domain\Resources\Domains\DomainResource::getUrl('view', ['record' => $domain]) }}" class="cdn-row-title">{{ $domain->display_name ?: $domain->name }}</a>
                            <span class="cdn-row-meta">
                                <x-ui.status-pill :tone="$domain->lifecycle_state->value === 'active' ? 'success' : 'warning'">{{ str($domain->lifecycle_state->value)->replace('_', ' ')->headline() }}</x-ui.status-pill>
                                <span class="cdn-mono">{{ $domain->dns_records_count }} records, {{ $domain->proxied_records_count }} proxied</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
            <a class="cdn-widget-link" href="{{ $domainsUrl }}">All domains</a>
        @endif
    </div>
</x-filament-panels::page>
