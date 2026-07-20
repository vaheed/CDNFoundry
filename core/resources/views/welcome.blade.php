<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="description" content="CDNFoundry private CDN control plane">
        <title>CDNFoundry</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="cdn-landing-body">
        <main class="cdn-landing-shell">
            <header class="cdn-landing-header">
                <a class="cdn-landing-brand" href="/" aria-label="CDNFoundry home">
                    <span class="cdn-landing-mark" aria-hidden="true">C</span>
                    <span>CDNFoundry</span>
                </a>
                <a class="cdn-landing-link" href="/api/health">Service health</a>
            </header>

            <section class="cdn-landing-hero">
                <div>
                    <p class="cdn-landing-eyebrow">Private CDN control plane</p>
                    <h1>Operate DNS, edge delivery, TLS, cache, and security from one place.</h1>
                    <p class="cdn-landing-copy">Choose the workspace that matches your role. Runtime traffic and security decisions remain independent of this control plane.</p>
                    <div class="cdn-landing-actions">
                        <a class="cdn-landing-button" href="/app">Open domain workspace</a>
                        <a class="cdn-landing-button cdn-landing-button-secondary" href="/admin">Open administration</a>
                    </div>
                </div>

                <aside class="cdn-landing-panel" aria-label="Platform capabilities">
                    <div class="cdn-landing-panel-label">One consistent workflow</div>
                    <ul>
                        <li><span>01</span><div><strong>Declare</strong><small>Save validated desired state.</small></div></li>
                        <li><span>02</span><div><strong>Deploy</strong><small>Queue bounded, revisioned work.</small></div></li>
                        <li><span>03</span><div><strong>Observe</strong><small>Review operations and last-valid state.</small></div></li>
                    </ul>
                </aside>
            </section>
        </main>
    </body>
</html>
