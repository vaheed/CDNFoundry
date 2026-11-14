<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            return response()->json(['message' => 'Idempotency-Key must be a UUID.', 'code' => 'invalid_idempotency_key', 'details' => (object) []], 422);
        }

        $userId = $request->user()?->getKey();
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getQueryString().'|'.$request->getContent());
        $lock = Cache::lock('idempotency:'.hash('sha256', (string) $userId.'|'.$key), 15);

        try {
            return $lock->block(5, fn (): Response => DB::transaction(function () use ($request, $next, $userId, $key, $hash): Response {
                // The cache lease is a contention hint. PostgreSQL owns the
                // transaction and lock until both mutation and receipt commit,
                // even if a worker dies or the cache lease expires mid-request.
                if (DB::getDriverName() === 'pgsql') {
                    $acquired = DB::selectOne('SELECT pg_try_advisory_xact_lock(1128549959, hashtext(?)) AS acquired', [(string) $userId.'|'.$key])->acquired;
                    if (! $acquired) {
                        return response()->json(['message' => 'A request with this idempotency key is still being processed.', 'code' => 'idempotency_busy', 'details' => (object) []], 409);
                    }
                }
                $existing = IdempotencyKey::query()->where('user_id', $userId)->where('key', $key)->first();
                if ($existing !== null && $existing->expires_at !== null && $existing->expires_at->isPast()) {
                    $existing->delete();
                    $existing = null;
                }
                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        return response()->json(['message' => 'This key was used with a different request.', 'code' => 'idempotency_conflict', 'details' => (object) []], 409);
                    }

                    return response()->json($existing->response_body, $existing->response_status)
                        ->header('Idempotency-Replayed', 'true');
                }

                /** @var Response $response */
                $response = $next($request);
                if ($response->getStatusCode() >= 500) {
                    throw new HttpResponseException($response);
                }
                if ($response instanceof JsonResponse && $this->replayable($response->getStatusCode())) {
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
            }));
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'A request with this idempotency key is still being processed.', 'code' => 'idempotency_busy', 'details' => (object) []], 409);
        }
    }

    /**
     * Only outcomes the same request would produce again are stored. A 429,
     * 403, 409, or 423 can change on the next attempt, so a retry must run.
     */
    private function replayable(int $status): bool
    {
        return ($status >= 200 && $status < 300) || in_array($status, [400, 404, 405, 410, 413, 415, 422], true);
    }

    private function replaySafe(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }
        $omitted = false;
        $strip = function (array $value) use (&$strip, &$omitted): array {
            foreach ($value as $field => $item) {
                if (in_array($field, ['token', 'bootstrap_token', 'signing_secret'], true)) {
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
