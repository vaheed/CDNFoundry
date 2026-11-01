@php
    $domain = $getRecord();
    $devActive = $domain->cache_development_mode_until !== null && $domain->cache_development_mode_until->isFuture();
    $maintenance = is_array($domain->proxy_settings['maintenance'] ?? null);
    $active = $domain->lifecycle_state === \App\Enums\DomainLifecycleState::Active;
    $quick = [
        ['label' => 'Purge cache', 'text' => 'Remove cached content for this domain.', 'action' => 'purgeCache', 'button' => 'Purge', 'on' => null],
        ['label' => 'Development mode', 'text' => 'Bypass the cache while you change the site.', 'action' => $devActive ? 'disableDevelopmentMode' : 'developmentMode', 'button' => $devActive ? 'Turn off' : 'Turn on', 'on' => $devActive],
        ['label' => 'Maintenance mode', 'text' => 'Show visitors a maintenance page.', 'action' => $maintenance ? 'endMaintenance' : 'startMaintenance', 'button' => $maintenance ? 'Turn off' : 'Turn on', 'on' => $maintenance],
    ];
    if ($active) {
        $quick[] = ['label' => 'Reconcile DNS', 'text' => 'Republish the zone; the previous zone stays until the new one is active.', 'action' => 'reconcileDns', 'button' => 'Run', 'on' => null];
    } else {
        $quick[] = ['label' => 'Verify nameservers', 'text' => 'Check the delegation at your registrar.', 'action' => 'verifyNameservers', 'button' => 'Verify', 'on' => null];
    }
@endphp

<aside class="cdn-rail" data-testid="domain-rail" aria-label="Domain summary">
    <section>
        <h3 class="cdn-rail-title">DNS</h3>
        <dl class="cdn-rail-list">
            <div><dt>Setup</dt><dd>{{ $domain->nameservers_verified_at ? 'Full, delegation verified' : 'Waiting for delegation' }}</dd></div>
            <div><dt>Records</dt><dd class="cdn-mono">{{ number_format($domain->dnsRecords()->count()) }}</dd></div>
            <div><dt>Nameservers</dt><dd class="cdn-mono">@forelse ($domain->assignedNameservers() as $nameserver)<span class="block">{{ $nameserver }}</span>@empty<span class="cdn-dl-empty">Not assigned yet</span>@endforelse</dd></div>
        </dl>
    </section>

    <section>
        <h3 class="cdn-rail-title">Quick actions</h3>
        <ul class="cdn-rail-actions">
            @foreach ($quick as $item)
                <li>
                    <div class="min-w-0">
                        <div class="cdn-setting-label">{{ $item['label'] }}@if ($item['on'] === true) <x-ui.status-pill tone="warning">On</x-ui.status-pill>@endif</div>
                        <div class="cdn-setting-description">{{ $item['text'] }}</div>
                    </div>
                    <x-filament::button size="sm" color="gray" wire:click="mountAction('{{ $item['action'] }}')" data-testid="domain-quick-{{ str($item['action'])->kebab() }}">{{ $item['button'] }}</x-filament::button>
                </li>
            @endforeach
        </ul>
    </section>
</aside>
