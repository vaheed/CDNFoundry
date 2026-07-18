<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return $next($request);
        }
        if (! Str::isUuid($key)) {
            return response()->json(['error' => ['code' => 'invalid_idempotency_key', 'message' => 'Idempotency-Key must be a UUID.']], 422);
        }

        $userId = $request->user()?->getKey();
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        $lock = Cache::lock('idempotency:'.hash('sha256', (string) $userId.'|'.$key), 15);

        try {
            return $lock->block(5, function () use ($request, $next, $userId, $key, $hash): Response {
                $existing = IdempotencyKey::query()->where('user_id', $userId)->where('key', $key)->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        return response()->json(['error' => ['code' => 'idempotency_conflict', 'message' => 'This key was used with a different request.']], 409);
                    }

                    return response()->json($existing->response_body, $existing->response_status)
                        ->header('Idempotency-Replayed', 'true');
                }

                /** @var Response $response */
                $response = $next($request);
                if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
                    IdempotencyKey::query()->create([
                        'user_id' => $userId,
                        'key' => $key,
                        'request_hash' => $hash,
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $this->replaySafe($response->getData(true)),
                        'expires_at' => now()->addDay(),
                    ]);
                }

                return $response;
            });
        } catch (LockTimeoutException) {
            return response()->json(['error' => ['code' => 'idempotency_busy', 'message' => 'A request with this idempotency key is still being processed.']], 409);
        }
    }

    private function replaySafe(array $body): array
    {
        $omitted = false;
        $strip = function (array $value) use (&$strip, &$omitted): array {
            foreach ($value as $field => $item) {
                if (in_array($field, ['token', 'bootstrap_token'], true)) {
                    unset($value[$field]);
                    $omitted = true;
                } elseif (is_array($item)) {
                    $value[$field] = $strip($item);
                }
            }

            return $value;
        };
        $safe = $strip($body);
        if ($omitted && isset($safe['data']) && is_array($safe['data'])) {
            $safe['data']['secret_replay_omitted'] = true;
        }

        return $safe;
    }
}
