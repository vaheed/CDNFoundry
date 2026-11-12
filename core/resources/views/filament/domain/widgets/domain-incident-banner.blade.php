<x-filament-widgets::widget>
    @if ($active)
        <section class="cdn-attention" data-testid="incident-banner" aria-labelledby="incident-banner-heading">
            <h2 id="incident-banner-heading" class="cdn-attention-title">Incident profile is on <x-ui.status-pill tone="warning">Ends {{ $domain->incident_expires_at->diffForHumans() }}</x-ui.status-pill></h2>
            <p class="cdn-row-meta" data-testid="incident-banner-meta">
                Started {{ $domain->incident_started_at->diffForHumans() }}{{ $starter ? ' by '.$starter : ' by the platform' }}. It ends at
                <span title="{{ $domain->incident_expires_at->toDateTimeString() }} UTC">{{ app(\App\Support\PlatformSettings::class)->formatTimestamp($domain->incident_expires_at) }}</span>, or when you end it.
            </p>
            <ul class="cdn-condition-list">
                @foreach ($protections as $protection)
                    <li class="cdn-condition-row" data-tone="{{ $protection['enforced'] ? 'success' : 'neutral' }}" data-testid="incident-protection">
                        <div class="min-w-0">
                            <div class="cdn-row-title">{{ $protection['label'] }}</div>
                            <div class="cdn-row-meta">{{ $protection['enforced'] ? 'Active' : 'Sent to the cells; takes effect with the updated cell runtime' }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="cdn-row-meta" data-testid="incident-limits-note">This protects your origin and the cells from request floods. It cannot absorb traffic that saturates a host, a link, or an upstream.</p>
        </section>
    @endif
</x-filament-widgets::widget>
