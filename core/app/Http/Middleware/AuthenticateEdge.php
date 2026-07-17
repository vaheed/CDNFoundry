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
        $token = $request->bearerToken();
        $edge = $token ? Edge::query()->where('identity_hash', hash('sha256', $token))->whereNull('identity_revoked_at')->first() : null;
        abort_if($edge === null, 401, 'A valid edge identity is required.');
        $request->attributes->set('edge', $edge);

        return $next($request);
    }
}
