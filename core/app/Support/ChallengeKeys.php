<?php

namespace App\Support;

use App\Models\ChallengeKey;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle of the platform's challenge keys: one active key signs, a rotated key keeps verifying until its overlap ends, a
 * revoked key verifies nothing. Cells receive the keys sealed to each edge's identity key (never in a snapshot) and apply them
 * by their own clock, so a retired key stops working even when the control plane is unreachable.
 */
final class ChallengeKeys
{
    /** A token lives 1 hour and a seed 5 minutes (cells), so a rotated key verifies for both plus margin. */
    public const OVERLAP_SECONDS = 7200;

    /** The active key is replaced after this long. */
    public const ROTATE_AFTER_DAYS = 30;

    public const RETIRED_KEPT_DAYS = 90;

    /** The active key, created on first use. */
    public static function ensureActive(): ChallengeKey
    {
        $active = ChallengeKey::query()->where('state', 'active')->first();

        return $active ?? self::rotate();
    }

    /** Makes a new key active. The key that was active keeps verifying until the overlap ends; an older previous key is retired. */
    public static function rotate(): ChallengeKey
    {
        return DB::transaction(function (): ChallengeKey {
            $rows = ChallengeKey::query()->whereIn('state', ['active', 'previous'])->lockForUpdate()->get();
            $revision = (int) ChallengeKey::query()->max('revision');
            foreach ($rows->where('state', 'previous') as $previous) {
                $previous->update(['state' => 'retired', 'retired_at' => now(), 'retired_reason' => 'superseded', 'revision' => ++$revision]);
            }
            foreach ($rows->where('state', 'active') as $active) {
                $active->update(['state' => 'previous', 'retire_at' => now()->addSeconds(self::OVERLAP_SECONDS), 'revision' => ++$revision]);
            }

            return ChallengeKey::query()->create([
                'key_id' => bin2hex(random_bytes(8)), 'secret_ciphertext' => bin2hex(random_bytes(32)), 'state' => 'active',
                'revision' => ++$revision, 'activated_at' => now(),
            ]);
        });
    }

    /**
     * Stops a key at once. A revoked active key is replaced first, so the platform never has no signing key. Tokens the key signed
     * stop working on every cell once the cell receives the new set.
     */
    public static function revoke(string $keyId): ChallengeKey
    {
        return DB::transaction(function () use ($keyId): ChallengeKey {
            $key = ChallengeKey::query()->where('key_id', $keyId)->whereIn('state', ['active', 'previous'])->lockForUpdate()->firstOrFail();
            if ($key->state === 'active') {
                self::rotate();
                $key->refresh();
            }
            $key->update(['state' => 'retired', 'retired_at' => now(), 'retired_reason' => 'revoked', 'revision' => (int) ChallengeKey::query()->max('revision') + 1]);

            return $key;
        });
    }

    /** Rotates an old active key and retires keys whose overlap is over. Returns what changed. */
    public static function maintain(): array
    {
        $changed = ['rotated' => false, 'retired' => 0, 'pruned' => 0];
        $active = ChallengeKey::query()->where('state', 'active')->first();
        if ($active === null || $active->activated_at->lte(now()->subDays(self::ROTATE_AFTER_DAYS))) {
            self::rotate();
            $changed['rotated'] = true;
        }
        DB::transaction(function () use (&$changed): void {
            foreach (ChallengeKey::query()->where('state', 'previous')->where('retire_at', '<=', now())->lockForUpdate()->get() as $key) {
                $key->update(['state' => 'retired', 'retired_at' => now(), 'retired_reason' => 'expired', 'revision' => (int) ChallengeKey::query()->max('revision') + 1]);
                $changed['retired']++;
            }
        });
        $changed['pruned'] = ChallengeKey::query()->where('state', 'retired')->where('retired_at', '<', now()->subDays(self::RETIRED_KEPT_DAYS))->delete();

        return $changed;
    }

    /**
     * What a cell is given: the version and the keys it may use, the secret in hex, `signing` for the one active key and
     * `not_after` (a Unix time) for a key that is only verifying.
     *
     * @return array{version: int, keys: list<array{id: string, secret: string, signing: bool, not_after: ?int}>}
     */
    public static function delivery(): array
    {
        self::ensureActive();
        $keys = ChallengeKey::query()->whereIn('state', ['active', 'previous'])->orderBy('state')->orderByDesc('id')->get();

        return [
            'version' => (int) ChallengeKey::query()->max('revision'),
            'keys' => $keys->map(fn (ChallengeKey $key): array => [
                'id' => $key->key_id, 'secret' => $key->secret, 'signing' => $key->state === 'active', 'not_after' => $key->retire_at?->timestamp,
            ])->values()->all(),
        ];
    }
}
