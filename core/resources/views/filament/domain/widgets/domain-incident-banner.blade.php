<x-filament-widgets::widget>
    @if ($active)
        @php($settings = app(\App\Support\PlatformSettings::class))
        <section class="cdn-attention cdn-incident" data-testid="incident-banner" aria-labelledby="incident-banner-heading">
            <div class="cdn-incident-head">
                <div class="min-w-0">
                    <h2 id="incident-banner-heading" class="cdn-incident-title">Under attack mode is on</h2>
                    <p class="cdn-row-meta" data-testid="incident-banner-meta">
                        Started {{ $domain->incident_started_at->diffForHumans() }}{{ $starter ? ' by '.$starter : ' by the platform' }}. Ends
                        <time datetime="{{ $domain->incident_expires_at->toIso8601String() }}" title="{{ $settings->formatTimestamp($domain->incident_expires_at) }}">{{ $domain->incident_expires_at->diffForHumans() }}</time>.
                    </p>
                </div>
                <x-filament::button size="md" color="primary" icon="phosphor-shield-check" class="cdn-incident-end" wire:click="$dispatch('open-domain-action', { name: 'endIncident' })" data-testid="incident-banner-end">End now</x-filament::button>
            </div>
            <ul class="cdn-incident-list">
                @foreach ($protections as $protection)
                    <li data-testid="incident-protection">
                        <span class="min-w-0">{{ $protection['label'] }}</span>
                        <x-ui.status-pill :tone="$protection['enforced'] ? 'success' : 'neutral'" appearance="dot">{{ $protection['enforced'] ? 'Active' : 'Not enforced yet' }}</x-ui.status-pill>
                    </li>
                @endforeach
            </ul>
            <p class="cdn-incident-note cdn-row-meta" data-testid="incident-limits-note">This protects your origin and the cells from request floods. It cannot absorb traffic that saturates a host, a link, or an upstream.</p>
        </section>
    @endif
</x-filament-widgets::widget>
