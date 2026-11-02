@php
    $domain = $getRecord();
    $devActive = $domain->cache_development_mode_until !== null && $domain->cache_development_mode_until->isFuture();
    $maintenance = is_array($domain->proxy_settings['maintenance'] ?? null);
    $active = $domain->lifecycle_state === \App\Enums\DomainLifecycleState::Active;
    $quick = [
        ['label' => 'Purge cache', 'action' => 'purgeCache', 'on' => null],
        ['label' => 'Development mode', 'action' => $devActive ? 'disableDevelopmentMode' : 'developmentMode', 'on' => $devActive],
        ['label' => 'Maintenance mode', 'action' => $maintenance ? 'endMaintenance' : 'startMaintenance', 'on' => $maintenance],
        $active
            ? ['label' => 'Reconcile DNS', 'action' => 'reconcileDns', 'on' => null]
            : ['label' => 'Verify nameservers', 'action' => 'verifyNameservers', 'on' => null],
    ];
@endphp

<div class="cdn-quick" data-testid="domain-quick-actions" role="group" aria-label="Quick actions">
    @foreach ($quick as $item)
        <x-filament::button size="sm" color="gray" wire:click="mountAction('{{ $item['action'] }}')" data-testid="domain-quick-{{ str($item['action'])->kebab() }}">
            {{ $item['label'] }}@if ($item['on'] !== null)<span class="cdn-quick-state" data-on="{{ $item['on'] ? 'true' : 'false' }}">{{ $item['on'] ? 'On' : 'Off' }}</span>@endif
        </x-filament::button>
    @endforeach
</div>
