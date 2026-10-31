<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits a bearer token to its abilities and domains. Browser sessions and full-access tokens pass.
 * `read` allows GET and HEAD, `dns` the DNS record routes of a domain, `purge` cache purges.
 */
class EnforceTokenScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof ApiToken || ($token->isFullAccess() && $token->scopedDomainIds() === null)) {
            return $next($request);
        }

        $path = $request->path();
        $abilities = $token->abilities ?? [];
        $scoped = $token->scopedDomainIds() !== null;
        $isDomainRoute = str_starts_with($path, 'api/domains/');
        $allowed = $token->isFullAccess()
            || ($request->isMethodSafe() && in_array('read', $abilities, true))
            || ($isDomainRoute && in_array('dns', $abilities, true) && $this->isDnsRoute($path))
            || ($isDomainRoute && in_array('purge', $abilities, true) && $this->isPurgeRoute($request, $path))
            || $path === 'api/auth/logout';

        // A scoped token cannot mint, list, or revoke tokens or change credentials, so it cannot widen itself.
        $credentialRoute = str_starts_with($path, 'api/me/') || ($path === 'api/me' && ! $request->isMethod('GET'));
        $allowed = $allowed && ! $credentialRoute;
        // A domain-limited token is confined to routes of its domains, the domain list, and operation polling.
        if ($scoped && str_starts_with($path, 'api/admin/')) {
            $allowed = false;
        }

        if (! $allowed) {
            return response()->json(['message' => 'This API token is not allowed to do that.', 'code' => 'token_scope_exceeded', 'details' => (object) []], 403);
        }

        return $next($request);
    }

    private function isDnsRoute(string $path): bool
    {
        return (bool) preg_match('#^api/domains/[^/]+/dns(/|$)#', $path) && ! str_contains($path, '/origin');
    }

    private function isPurgeRoute(Request $request, string $path): bool
    {
        return (bool) preg_match('#^api/domains/[^/]+/cache/purges?(/|$)#', $path)
            && ($request->isMethodSafe() || $request->isMethod('POST') && str_ends_with($path, '/cache/purge'));
    }
}
