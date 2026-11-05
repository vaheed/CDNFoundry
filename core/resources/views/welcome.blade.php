<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="description" content="CDNFoundry private CDN control plane">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <title>CDNFoundry</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="cdn-public">
        <header class="cdn-public-header">
            <a class="cdn-public-brand" href="/" aria-label="CDNFoundry home">CDNFoundry</a>
            <nav class="cdn-public-nav" aria-label="Main navigation">
                <a href="{{ route('service-health') }}">Service health</a>
                <a href="https://vaheed.github.io/CDNFoundry/" target="_blank" rel="noopener">Documentation</a>
            </nav>
        </header>

        <main class="cdn-public-main cdn-public-main--narrow">
            <section class="cdn-card" aria-labelledby="sign-in-title">
                <div class="cdn-card-body">
                    <h1 id="sign-in-title">Sign in to CDNFoundry</h1>
                    <p class="cdn-muted">Private CDN control plane for DNS, edge delivery, TLS, cache, and security.</p>
                </div>
                <ul class="cdn-choice-list">
                    <li>
                        <a class="cdn-choice" href="/app" data-testid="landing-app-link">
                            <span>
                                <strong>Domain workspace</strong>
                                <small>Manage the domains assigned to you: DNS records, proxy, cache, and analytics.</small>
                            </span>
                            <span class="cdn-choice-arrow" aria-hidden="true">›</span>
                        </a>
                    </li>
                    <li>
                        <a class="cdn-choice" href="/admin" data-testid="landing-admin-link">
                            <span>
                                <strong>Administration</strong>
                                <small>Operate the platform: edges, service pools, the DNS primary, users, and settings.</small>
                            </span>
                            <span class="cdn-choice-arrow" aria-hidden="true">›</span>
                        </a>
                    </li>
                </ul>
            </section>
        </main>
    </body>
</html>
