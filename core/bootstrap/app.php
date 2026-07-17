<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureHorizonAdmin;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
            'idempotent' => IdempotentRequest::class,
            'horizon.admin' => EnsureHorizonAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'The provided data is invalid.',
                'code' => 'validation_failed',
                'errors' => $exception->errors(),
            ], 422);
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'unauthenticated',
                'details' => (object) [],
            ], 401);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $codes = [401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'unprocessable'];

            return response()->json([
                'message' => $exception->getMessage() ?: 'The operation could not be completed.',
                'code' => $codes[$status] ?? 'http_error',
                'details' => (object) [],
            ], $status, $exception->getHeaders());
        });
    })->create();
