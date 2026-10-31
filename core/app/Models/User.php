<?php

namespace App\Models;

use App\Enums\UserType;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
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
use PragmaRX\Google2FA\Google2FA;

#[Fillable(['name', 'email', 'password', 'type', 'disabled_at'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const MAX_ACTIVE_TOKENS = 50;

    /** False when the request authenticates with a token limited to other domains. */
    public function tokenCoversDomain(Domain $domain): bool
    {
        $token = $this->currentAccessToken();
        if (! $token instanceof ApiToken) {
            return true;
        }
        $ids = $token->scopedDomainIds();

        return $ids === null || in_array($domain->getKey(), $ids, true);
    }

    /** @return list<int>|null the domains the current token is limited to, or null when it is not limited */
    public function tokenDomainIds(): ?array
    {
        $token = $this->currentAccessToken();

        return $token instanceof ApiToken ? $token->scopedDomainIds() : null;
    }

    /**
     * @param  list<string>  $abilities
     * @param  list<int>  $domainIds
     */
    public function createTokenWithinLimit(string $name, string $field = 'name', array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null, array $domainIds = []): NewAccessToken
    {
        return DB::transaction(function () use ($name, $field, $abilities, $expiresAt, $domainIds): NewAccessToken {
            $user = self::query()->lockForUpdate()->findOrFail($this->getKey());
            abort_if($user->isDisabled(), 403, 'This account is disabled.');
            if ($user->tokens()->count() >= self::MAX_ACTIVE_TOKENS) {
                throw ValidationException::withMessages([
                    $field => 'At most '.self::MAX_ACTIVE_TOKENS.' active API tokens are allowed. Revoke an old token before creating another.',
                ]);
            }

            $created = $user->createToken($name, $abilities, $expiresAt ?? now()->addDays(ApiToken::DEFAULT_LIFETIME_DAYS));
            $created->accessToken->forceFill(['token_last_six' => substr($created->plainTextToken, -6)])->save();
            $created->accessToken->domains()->attach($domainIds);

            return $created;
        });
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
        // Turning two-step sign-in on or off changes how the account proves itself: end every API session.
        $this->tokens()->delete();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return list<string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    public function hasTwoStepSignIn(): bool
    {
        return filled($this->app_authentication_secret);
    }

    /** Accept a current code, or burn one recovery code. */
    public function passesTwoStepChallenge(?string $code): bool
    {
        $code = preg_replace('/\s+/', '', (string) $code);
        if ($code === '') {
            return false;
        }
        if (preg_match('/^\d{6}$/', $code) === 1) {
            return (new Google2FA)->verifyKey((string) $this->app_authentication_secret, $code, 1);
        }

        $codes = $this->app_authentication_recovery_codes ?? [];
        foreach ($codes as $index => $stored) {
            if (hash_equals((string) $stored, $code)) {
                unset($codes[$index]);
                $this->saveAppAuthenticationRecoveryCodes(array_values($codes));

                return true;
            }
        }

        return false;
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
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'type' => UserType::class,
        ];
    }
}
