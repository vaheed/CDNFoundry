<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\ControlPlaneMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts every request by route pattern and status class, times it, and counts API token use by ability.
 * It records after the response is sent, so it adds nothing to the latency it measures.
 */
class RecordRequestMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('metrics_started_at', hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('metrics_started_at');
        // The scrape endpoint and health probes would only measure themselves.
        if (! is_int($started) || $request->is('metrics', 'up', 'health', 'api/health', 'api/ready')) {
            return;
        }
        ControlPlaneMetrics::recordRequest($request->route()?->uri(), $response->getStatusCode(), (hrtime(true) - $started) / 1e9);
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof ApiToken) {
            ControlPlaneMetrics::recordTokenUse($token->abilities ?? []);
        }
    }
}
