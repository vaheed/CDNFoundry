<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A domain whose nameservers are not verified yet may only be viewed, verified, and given DNS records. Everything that
 * reaches an edge, a certificate, or the security and analytics features answers 409 until the delegation is proven.
 */
class EnsureDomainIsVerified
{
    /** Routes under api/domains/{domain} that work before verification. */
    private const ALLOWED = [
        '', '/status', '/verify-nameservers', '/activate', '/disable',
        '/dns/records', '/dns/records/bulk', '/dns/records/{record}', '/dns/import', '/dns/export', '/dns/deployment', '/dns/reconcile',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $domain = $request->route('domain');
        $uri = $request->route()?->uri() ?? '';
        if (! $domain instanceof Domain || ! str_starts_with($uri, 'api/domains/{domain}') || $domain->isVerified()) {
            return $next($request);
        }
        $suffix = substr($uri, strlen('api/domains/{domain}'));
        if (in_array($suffix, self::ALLOWED, true)) {
            return $next($request);
        }

        return response()->json(['message' => 'Verify the domain\'s nameservers first. Until then only its DNS records can be managed.', 'code' => 'domain_not_verified', 'details' => (object) []], 409);
    }
}
