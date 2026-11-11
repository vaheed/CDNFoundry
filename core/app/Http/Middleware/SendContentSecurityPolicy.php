<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One Content-Security-Policy for the panels and the API. Scripts run only from this origin or with the
 * request's nonce; nothing is loaded from another origin. `security.content_security_policy` selects
 * `enforce` (default), `report-only` (observe violations first), or `off`.
 */
class SendContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('security.content_security_policy', 'enforce');
        if ($mode === 'off') {
            return $next($request);
        }
        $nonce = Str::random(24);
        Vite::useCspNonce($nonce);
        $response = $next($request);
        $response->headers->set($mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', self::policy($nonce));

        return $response;
    }

    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            // Alpine evaluates the expressions of its templates, which needs 'unsafe-eval' until the panels move to its CSP build.
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            'upgrade-insecure-requests',
        ]);
    }
}
