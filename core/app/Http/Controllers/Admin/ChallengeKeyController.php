<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ChallengeKey;
use App\Support\ChallengeKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's challenge keys: what each key is doing and when it ends, with rotate and revoke. A secret is never returned.
 * A change reaches the cells asynchronously, when each edge agent next fetches the keys, and a cell applies a key's end by its own
 * clock.
 */
class ChallengeKeyController extends Controller
{
    public function index(): JsonResponse
    {
        ChallengeKeys::ensureActive();

        return response()->json([
            'data' => ChallengeKey::query()->where('state', '!=', 'retired')->orderBy('state')->orderByDesc('id')->get()->map(fn (ChallengeKey $key): array => self::describe($key))->all(),
            'version' => (int) ChallengeKey::query()->max('revision'),
            'overlap_seconds' => ChallengeKeys::OVERLAP_SECONDS,
        ]);
    }

    public function rotate(Request $request): JsonResponse
    {
        $key = ChallengeKeys::rotate();
        AuditLog::record($request->user(), 'challenge_key.rotated', $key, ['key_id' => $key->key_id], $request->ip());

        return response()->json(['data' => self::describe($key)], 201);
    }

    public function revoke(Request $request, string $keyId): JsonResponse
    {
        $key = ChallengeKeys::revoke($keyId);
        AuditLog::record($request->user(), 'challenge_key.revoked', $key, ['key_id' => $key->key_id], $request->ip());

        return response()->json(['data' => self::describe($key)]);
    }

    /** @return array<string, mixed> */
    public static function describe(ChallengeKey $key): array
    {
        return [
            'id' => $key->key_id, 'state' => $key->state, 'activated_at' => $key->activated_at->toIso8601String(),
            'retire_at' => $key->retire_at?->toIso8601String(), 'retired_at' => $key->retired_at?->toIso8601String(), 'retired_reason' => $key->retired_reason,
        ];
    }
}
