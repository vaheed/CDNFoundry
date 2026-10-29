<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDisabled()) {
            $request->user()->tokens()->delete();

            return response()->json(['message' => 'This account is disabled.', 'code' => 'account_disabled', 'details' => (object) []], 403);
        }

        return $next($request);
    }
}
