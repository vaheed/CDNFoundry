<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An administrator acting as a domain user in the domain panel. The session remembers the administrator, every audit entry
 * written meanwhile names them, and nothing that outlives the session (an API token, a password) can be created as the user.
 */
final class Impersonation
{
    private const SESSION_KEY = 'impersonator_id';

    /** Whether the administrator may act as this user: an active domain user who is not the administrator. */
    public static function allowed(?User $administrator, User $user): bool
    {
        return $administrator?->isAdmin() === true && ! $user->isAdmin() && ! $user->isDisabled() && $administrator->isNot($user);
    }

    public static function start(Request $request, User $user): void
    {
        $administrator = $request->user();
        if (! $administrator instanceof User || ! self::allowed($administrator, $user)) {
            throw new HttpException(403, 'Only an administrator can act as an active domain user.');
        }
        AuditLog::record($administrator, 'user.impersonation_started', $user, ['user_id' => $user->id], $request->ip());
        self::switchTo($request, $user);
        session()->put(self::SESSION_KEY, $administrator->id);
    }

    /** Back to the administrator who started it. Returns false when the session was not acting as anyone. */
    public static function leave(Request $request): bool
    {
        $administrator = User::query()->find(session()->get(self::SESSION_KEY));
        $user = $request->user();
        if (! $administrator instanceof User || ! $administrator->isAdmin() || $administrator->isDisabled()) {
            return false;
        }
        AuditLog::record($administrator, 'user.impersonation_ended', $user, ['user_id' => $user?->getKey()], $request->ip());
        self::switchTo($request, $administrator);
        session()->forget(self::SESSION_KEY);

        return true;
    }

    public static function impersonatorId(): ?int
    {
        $id = session()->get(self::SESSION_KEY);

        return is_int($id) ? $id : null;
    }

    public static function active(): bool
    {
        return self::impersonatorId() !== null;
    }

    public static function administrator(): ?User
    {
        $id = self::impersonatorId();

        return $id === null ? null : User::query()->find($id);
    }

    /** Refuses actions whose result would outlive the session in the user's name. */
    public static function refuse(string $what): void
    {
        if (self::active()) {
            throw new HttpException(403, "You cannot {$what} while acting as another user.");
        }
    }

    private static function switchTo(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        // A new session id, and the stored password hash of the previous user dropped so the session check binds the new one.
        session()->regenerate();
        session()->forget('password_hash_web');
    }
}
