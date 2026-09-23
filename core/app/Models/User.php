<?php

namespace App\Models;

use App\Enums\UserType;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

#[Fillable(['name', 'email', 'password', 'type', 'disabled_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const MAX_ACTIVE_TOKENS = 50;

    public function createTokenWithinLimit(string $name, string $field = 'name'): NewAccessToken
    {
        return DB::transaction(function () use ($name, $field): NewAccessToken {
            $user = self::query()->lockForUpdate()->findOrFail($this->getKey());
            abort_if($user->isDisabled(), 403, 'This account is disabled.');
            if ($user->tokens()->count() >= self::MAX_ACTIVE_TOKENS) {
                throw ValidationException::withMessages([
                    $field => 'At most '.self::MAX_ACTIVE_TOKENS.' active API tokens are allowed. Revoke an old token before creating another.',
                ]);
            }

            return $user->createToken($name);
        });
    }

    public function isAdmin(): bool
    {
        return $this->type === UserType::Admin;
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class)->withPivot('created_at');
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isDisabled()) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->isAdmin(),
            'app' => ! $this->isAdmin(),
            default => false,
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
            'type' => UserType::class,
        ];
    }
}
