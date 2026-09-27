<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="description" content="Current CDNFoundry service health across DNS, edge, TLS, cache, security, telemetry, and the control plane">
        <meta http-equiv="refresh" content="60">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <title>Service health · CDNFoundry</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="cdn-health-body">
        <div class="cdn-health-shell">
            <header class="cdn-health-header">
                <a class="cdn-landing-brand" href="/" aria-label="CDNFoundry home">
                    <span class="cdn-landing-mark" aria-hidden="true">C</span>
                    <span>CDNFoundry</span>
                </a>
                <nav aria-label="Main navigation">
                    <a href="/">Home</a>
                    <a href="/app">Domain workspace</a>
                    <a href="/admin">Administration</a>
                </nav>
            </header>

            <main>
                <section class="cdn-health-intro" aria-labelledby="health-title">
                    <div>
                        <p class="cdn-health-kicker">Platform status</p>
                        <h1 id="health-title">Service health</h1>
                        <p>Current operational signals across the CDN. Edge traffic continues to serve from the last valid configuration when the control plane is unavailable.</p>
                    </div>
                    <div class="cdn-health-overall" data-status="{{ $status }}" role="status" aria-live="polite">
                        <span class="cdn-health-indicator" aria-hidden="true"></span>
                        <div>
                            <small>Current status</small>
                            <strong>{{ match ($status) { 'operational' => 'All systems operational', 'degraded' => 'Some systems degraded', 'outage' => 'Service disruption', default => 'Status unavailable' } }}</strong>
                        </div>
                    </div>
                </section>

                <div class="cdn-health-meta">
                    <span>{{ $fresh ? 'Updated' : 'Last reported' }}: <time @if ($snapshot['checked_at']) datetime="{{ $snapshot['checked_at'] }}" @endif>{{ $snapshot['checked_at'] ? \Carbon\Carbon::parse($snapshot['checked_at'])->utc()->format('M j, Y H:i:s') . ' UTC' : 'No report yet' }}</time></span>
                    <span>Refreshes every minute</span>
                </div>

                @unless ($fresh)
                    <div class="cdn-health-notice" role="alert">
                        Current checks are unavailable or older than two and a half minutes. The last report below is historical and does not confirm current delivery health.
                    </div>
                @endunless

                <section class="cdn-health-section" aria-labelledby="components-title">
                    <div class="cdn-health-section-heading">
                        <div>
                            <p class="cdn-health-kicker">System overview</p>
                            <h2 id="components-title">Components</h2>
                        </div>
                        <span>{{ count($snapshot['groups']) }} groups</span>
                    </div>

                    @if ($snapshot['groups'] === [])
                        <div class="cdn-health-empty">No operational snapshot is available yet. Check again after the scheduler publishes its next report.</div>
                    @else
                        <div class="cdn-health-grid">
                            @foreach ($snapshot['groups'] as $group)
                                <article class="cdn-health-card" data-status="{{ $fresh ? $group['status'] : 'unknown' }}">
                                    <div class="cdn-health-card-top">
                                        <div>
                                            <h3>{{ $group['label'] }}</h3>
                                            <p>{{ $group['description'] }}</p>
                                        </div>
                                        <span class="cdn-health-badge"><span aria-hidden="true"></span>{{ ucfirst($fresh ? $group['status'] : 'unknown') }}</span>
                                    </div>
                                    <ul>
                                        @foreach ($group['checks'] as $check)
                                            <li><span>{{ $check['name'] }}</span><strong data-status="{{ $fresh ? $check['status'] : 'unknown' }}">{{ ucfirst($fresh ? $check['status'] : 'unknown') }}</strong></li>
                                        @endforeach
                                    </ul>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>

                <aside class="cdn-health-scope" aria-labelledby="scope-title">
                    <h2 id="scope-title">About these checks</h2>
                    <p>Signals are sampled by the control plane once per minute and cover registered infrastructure, configuration delivery, certificate state, queued work, and telemetry processing. They are not a measurement of every customer request or a guarantee of availability from every location.</p>
                    <a href="/api/health?format=json" type="application/json">Liveness API</a>
                </aside>
            </main>

            <footer class="cdn-health-footer"><span>CDNFoundry</span><span>Private CDN operations</span></footer>
        </div>
    </body>
</html>
