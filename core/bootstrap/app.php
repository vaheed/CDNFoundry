<?php

use App\Exceptions\AnalyticsUnavailableException;
use App\Http\Middleware\AttachRequestContext;
use App\Http\Middleware\AuthenticateEdge;
use App\Http\Middleware\ControlEpoch;
use App\Http\Middleware\EnforceTokenScope;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureHorizonAdmin;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AttachRequestContext::class);
        $middleware->statefulApi();
        // Caddy terminates TLS in front of the control plane. Trust its forwarded
        // scheme and host so generated links (pagination, redirects) keep https.
        $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7'))))), headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'token.scope' => EnforceTokenScope::class,
            'admin' => EnsureUserIsAdmin::class,
            'idempotent' => IdempotentRequest::class,
            'horizon.admin' => EnsureHorizonAdmin::class,
            'edge.auth' => AuthenticateEdge::class,
            'control.epoch' => ControlEpoch::class,
        ]);
        $middleware->validateCsrfTokens(except: ['edge/v1/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response): Response {
            if ($requestId = Context::get('request_id')) {
                $response->headers->set('X-Request-ID', (string) $requestId);
            }

            return $response;
        });
        $exceptions->render(function (AnalyticsUnavailableException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Analytics are temporarily unavailable.', 'code' => 'analytics_unavailable', 'details' => (object) []], 503);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('edge/*'),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('edge/*')) {
                return null;
            }

            return response()->json([
                'message' => 'The provided data is invalid.',
                'code' => 'validation_failed',
                'errors' => $exception->errors(),
            ], 422);
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('edge/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'unauthenticated',
                'details' => (object) [],
            ], 401);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('edge/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $codes = [
                401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict',
                413 => 'payload_too_large', 422 => 'unprocessable', 429 => 'too_many_requests',
            ];
            // Framework messages for missing models name internal classes; clients get a fixed sentence.
            $message = $exception->getPrevious() instanceof ModelNotFoundException || str_starts_with($exception->getMessage(), 'No query results')
                ? 'The requested resource was not found.'
                : ($exception->getMessage() ?: 'The operation could not be completed.');

            return response()->json([
                'message' => $message,
                'code' => $codes[$status] ?? ($status >= 500 ? 'server_error' : 'http_error'),
                'details' => (object) [],
            ], $status, $exception->getHeaders());
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('edge/*') || config('app.debug')) {
                return null;
            }

            return response()->json(['message' => 'The server could not complete the request.', 'code' => 'server_error', 'details' => (object) []], 500);
        });
    })->create();
