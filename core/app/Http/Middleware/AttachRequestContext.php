<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachRequestContext
{
    private const REQUEST_ID_PATTERN = '/\A[A-Za-z0-9._:-]{1,96}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        Context::forget(['request_id', 'request_started_at', 'operation_id', 'domain_id']);
        $provided = trim((string) $request->header('X-Request-ID'));
        $requestId = preg_match(self::REQUEST_ID_PATTERN, $provided) === 1
            ? $provided
            : (string) Str::uuid();

        Context::add([
            'request_id' => $requestId,
            'request_started_at' => hrtime(true),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        Context::forget(['request_id', 'request_started_at', 'operation_id', 'domain_id']);
    }
}
