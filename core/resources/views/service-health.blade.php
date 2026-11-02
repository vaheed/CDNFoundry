<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="description" content="Current CDNFoundry service health across DNS, edge, TLS, cache, security, telemetry, and the control plane">
        <meta http-equiv="refresh" content="60">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <title>Service health: CDNFoundry</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="cdn-public">
        @php
            $tone = fn (string $state): string => match ($state) { 'operational' => 'success', 'degraded' => 'warning', 'outage' => 'danger', default => 'neutral' };
            $overall = match ($status) { 'operational' => 'All systems operational', 'degraded' => 'Some systems degraded', 'outage' => 'Service disruption', default => 'Status unavailable' };
        @endphp
        <header class="cdn-public-header">
            <a class="cdn-public-brand" href="/" aria-label="CDNFoundry home">CDNFoundry</a>
            <nav class="cdn-public-nav" aria-label="Main navigation">
                <a href="/app">Domain workspace</a>
                <a href="/admin">Administration</a>
                <a href="https://vaheed.github.io/CDNFoundry/" target="_blank" rel="noopener">Documentation</a>
            </nav>
        </header>

        <main class="cdn-public-main">
            <div class="cdn-page-title">
                <h1>Service health</h1>
                <p class="cdn-muted">
                    {{ $fresh ? 'Updated' : 'Last reported' }}
                    <time @if ($snapshot['checked_at']) datetime="{{ $snapshot['checked_at'] }}" @endif>{{ $snapshot['checked_at'] ? \Carbon\Carbon::parse($snapshot['checked_at'])->utc()->format('M j, Y H:i:s').' UTC' : 'never' }}</time>.
                    Refreshes every minute.
                </p>
            </div>

            <section class="cdn-banner" data-tone="{{ $tone($status) }}" role="status" aria-live="polite" data-testid="health-overall">
                <span class="cdn-badge" data-tone="{{ $tone($status) }}">{{ $status === 'unknown' || ! in_array($status, ['operational', 'degraded', 'outage'], true) ? 'Unknown' : ucfirst($status) }}</span>
                <strong>{{ $overall }}</strong>
            </section>

            @unless ($fresh)
                <p class="cdn-banner" data-tone="warning" role="alert">
                    Current checks are unavailable or older than two and a half minutes. The report below is historical and does not confirm current delivery health.
                </p>
            @endunless

            @if ($snapshot['groups'] === [])
                <div class="cdn-card cdn-card-body cdn-muted">No operational snapshot is available yet. Check again after the scheduler publishes its next report.</div>
            @else
                <div class="cdn-health-grid">
                    @foreach ($snapshot['groups'] as $group)
                        @php($groupState = $fresh ? $group['status'] : 'unknown')
                        <article class="cdn-card" data-testid="health-group">
                            <header class="cdn-card-header">
                                <div class="min-w-0">
                                    <h2>{{ $group['label'] }}</h2>
                                    <p class="cdn-muted">{{ $group['description'] }}</p>
                                </div>
                                <span class="cdn-badge" data-tone="{{ $tone($groupState) }}">{{ ucfirst($groupState) }}</span>
                            </header>
                            <ul class="cdn-rows">
                                @foreach ($group['checks'] as $check)
                                    @php($checkState = $fresh ? $check['status'] : 'unknown')
                                    <li><span>{{ $check['name'] }}</span><span class="cdn-badge" data-tone="{{ $tone($checkState) }}">{{ ucfirst($checkState) }}</span></li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>
            @endif

            <details class="cdn-card cdn-about">
                <summary>About these checks</summary>
                <p class="cdn-muted">The control plane samples these signals once per minute. They cover registered infrastructure, configuration delivery, certificate state, queued work, and telemetry processing. They do not measure every customer request or availability from every location. Edges keep serving the last valid configuration when the control plane is unavailable.</p>
                <a href="/api/health?format=json" type="application/json">Liveness API</a>
            </details>
        </main>
    </body>
</html>
