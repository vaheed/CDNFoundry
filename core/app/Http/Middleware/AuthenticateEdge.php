<?php

namespace App\Http\Middleware;

use App\Models\Edge;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEdge
{
    public function handle(Request $request, Closure $next): Response
    {
        $verified = $request->header('X-Edge-Certificate-Verify');
        $serial = strtoupper((string) $request->header('X-Edge-Certificate-Serial'));
        $edge = $verified === 'SUCCESS' && $serial !== ''
            ? Edge::query()->where('identity_certificate_serial', $serial)->where('identity_certificate_expires_at', '>', now())->whereNull('identity_revoked_at')->first()
            : null;
        abort_if($edge === null, 401, 'A valid edge identity is required.');
        $request->attributes->set('edge', $edge);

        return $next($request);
    }
}
