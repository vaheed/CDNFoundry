<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense in depth behind the Caddy allowlist: when management networks are configured, the administrator panel,
 * the administrator API, Horizon, the metrics endpoint, and the control-state page answer only clients inside them.
 * The customer panel (/app), the customer API, the status page, the health probes, and the edge listener stay open
 * to everyone. A refusal is a bare 403, decided before the session starts, so it sets no cookie and shows no login form.
 *
 * Callers that reach the application directly on the private container network carry no forwarded address and
 * are internal by construction. Everything that arrives through Caddy carries the client address Caddy saw.
 */
class RestrictToManagementNetworks
{
    private const RESTRICTED_PATHS = ['admin', 'admin/*', 'api/admin', 'api/admin/*', 'horizon', 'horizon/*', 'metrics', 'control-state'];

    public function handle(Request $request, Closure $next): Response
    {
        $networks = self::networks();
        if ($networks === [] || ! $request->is(...self::RESTRICTED_PATHS) || ! $request->headers->has('X-Forwarded-For')) {
            return $next($request);
        }
        if (IpUtils::checkIp((string) $request->ip(), $networks)) {
            return $next($request);
        }
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'This network may not use the control plane.', 'code' => 'network_not_allowed', 'details' => (object) []], 403);
        }

        return response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return list<string> */
    public static function networks(): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', (string) config('security.management_networks', '')) ?: []));
    }
}
