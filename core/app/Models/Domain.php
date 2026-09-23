<?php

namespace App\Models;

use App\Enums\DomainLifecycleState;
use App\Enums\UserType;
use App\Support\DomainName;
use App\Support\DomainNameserverVerification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'display_name', 'lifecycle_state', 'revision', 'nameservers_verified_at', 'nameservers_verified_by', 'disabled_at', 'deprovision_after', 'proxy_settings', 'active_edge_revision', 'cache_settings', 'cache_epoch', 'cache_development_mode_until', 'tls_mode', 'active_tls_certificate_id', 'security_settings', 'security_state', 'security_state_changed_at', 'waf_profile', 'delegation_token', 'delegation_nameservers', 'claim_expires_at'])]
class Domain extends Model
{
    use SoftDeletes;

    public function resolveRouteBinding($value, $field = null)
    {
        $domain = parent::resolveRouteBinding($value, $field);
        if ($domain !== null) {
            // Bind before idempotency replay as well as controller execution.
            Gate::authorize('view', $domain);
        }

        return $domain;
    }

    /** Serialize creation and finalization for a canonical name, including absent rows. */
    public static function lockCanonicalName(string $name): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Canonical domain locking requires a transaction.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(1128549958, hashtext(?))', [$name]);
        }
    }

    public static function createPendingFor(User $actor, string $submittedName, ?string $ipAddress = null): self
    {
        abort_if($actor->isDisabled(), 403);
        try {
            $name = DomainName::normalize($submittedName);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($actor, $name, $submittedName, $ipAddress): self {
            self::lockCanonicalName($name);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_if($actor->isDisabled(), 403);
            if (! $actor->isAdmin() && $actor->domains()->whereNull('nameservers_verified_at')->count() >= 20) {
                throw ValidationException::withMessages(['name' => 'At most 20 unverified domain claims may be pending for one user.']);
            }
            $platform = PlatformDnsSetting::query()->find(1)?->platform_domain;
            $control = parse_url((string) config('app.url'), PHP_URL_HOST);
            $management = is_string($control) && str_starts_with($control, 'control.') ? substr($control, 8) : $control;
            foreach (array_filter([$platform, $management]) as $protected) {
                $protected = strtolower(rtrim($protected, '.'));
                if ($name === $protected || str_ends_with($name, '.'.$protected) || str_ends_with($protected, '.'.$name)) {
                    throw ValidationException::withMessages(['name' => 'Platform and management namespaces cannot be claimed as customer zones.']);
                }
            }
            if (self::query()->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => 'This canonical domain is already managed.']);
            }
            if (DomainNameTombstone::query()->where('name', $name)->first()?->reclaim_after?->isFuture()) {
                throw ValidationException::withMessages(['name' => 'The domain is still in its post-deprovisioning reclaim cooldown.']);
            }
            $domain = self::query()->create([
                'name' => $name, 'display_name' => trim($submittedName),
                'lifecycle_state' => DomainLifecycleState::PendingVerification, 'revision' => 1,
                'delegation_token' => bin2hex(random_bytes(16)), 'claim_expires_at' => now()->addDays(7),
            ]);
            if (! $actor->isAdmin()) {
                $domain->users()->attach($actor->getKey());
            }
            AuditLog::record($actor, 'domain.created', $domain, ['name' => $domain->name], $ipAddress);
            app(DomainNameserverVerification::class)->queue($domain, $actor, $ipAddress, automatic: true);

            return $domain;
        });
    }

    /** @return list<string> */
    public function assignedNameservers(): array
    {
        if ($this->delegation_nameservers !== null) {
            return $this->delegation_nameservers;
        }
        // Legacy verified installations continue using the original shared pair.
        // A pending legacy claim may never verify against this fallback.
        if ($this->nameservers_verified_at === null) {
            return [];
        }

        return collect(PlatformDnsSetting::query()->find(1)?->nameservers ?? [])
            ->pluck('hostname')->map(fn (string $name): string => strtolower(rtrim($name, '.')))
            ->unique()->sort()->values()->all();
    }

    public function initializeDelegationClaim(): void
    {
        if ($this->nameservers_verified_at !== null || $this->delegation_nameservers !== null) {
            return;
        }
        $settings = PlatformDnsSetting::query()->find(1);
        if ($settings === null) {
            return;
        }
        $token = $this->delegation_token ?? bin2hex(random_bytes(16));
        $nameservers = collect($settings->nameservers)->pluck('hostname')->map(function (string $hostname) use ($token): string {
            $assigned = $token.'.'.strtolower(rtrim($hostname, '.'));
            if (strlen($assigned) > 253) {
                throw new \RuntimeException('Platform nameserver names must leave room for delegation assignments.');
            }

            return $assigned;
        })->unique()->sort()->values()->all();
        if (count($nameservers) < 2) {
            throw new \RuntimeException('At least two platform nameservers are required for delegation assignments.');
        }
        $this->forceFill([
            'delegation_token' => $token, 'delegation_nameservers' => $nameservers,
            'claim_expires_at' => $this->claim_expires_at ?? now()->addDays(7),
        ])->save();
    }

    public function hasManagedAncestor(): bool
    {
        $labels = explode('.', $this->name);
        $ancestors = [];
        while (count($labels) > 1) {
            array_shift($labels);
            $ancestors[] = implode('.', $labels);
        }

        return self::query()->whereIn('name', $ancestors)->exists();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('created_at');
    }

    /** @return array{0: User, 1: bool} */
    public function assignActiveUser(User $actor, int $userId, ?string $ipAddress = null): array
    {
        return DB::transaction(function () use ($actor, $userId, $ipAddress): array {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if ($user->type !== UserType::User || $user->isDisabled()) {
                throw ValidationException::withMessages(['user_id' => 'Only active domain users may be assigned.']);
            }
            $attached = $this->users()->syncWithoutDetaching([$user->id]);
            $created = $attached['attached'] !== [];
            if ($created) {
                AuditLog::record($actor, 'domain.user_assigned', $this, ['user_id' => $user->id], $ipAddress);
            }

            return [$user, $created];
        });
    }

    public function dnsRecords(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function dnsDeployments(): HasMany
    {
        return $this->hasMany(DnsDeployment::class);
    }

    public function edgePlacement(): HasOne
    {
        return $this->hasOne(DomainEdgePlacement::class);
    }

    public function edgeCells(): HasMany
    {
        return $this->hasMany(DomainEdgeCell::class);
    }

    public function tlsCertificates(): HasMany
    {
        return $this->hasMany(TlsCertificate::class);
    }

    public function tlsOrders(): HasMany
    {
        return $this->hasMany(TlsOrder::class);
    }

    public function latestTlsOrder(): HasOne
    {
        return $this->hasOne(TlsOrder::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function securityRules(): HasMany
    {
        return $this->hasMany(SecurityRule::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    public function wafExclusions(): HasMany
    {
        return $this->hasMany(WafExclusion::class);
    }

    public function usageRollups(): HasMany
    {
        return $this->hasMany(UsageRollup::class);
    }

    public function activeTlsCertificate(): BelongsTo
    {
        return $this->belongsTo(TlsCertificate::class, 'active_tls_certificate_id');
    }

    protected function casts(): array
    {
        return [
            'delegation_nameservers' => 'array',
            'claim_expires_at' => 'immutable_datetime',
            'lifecycle_state' => DomainLifecycleState::class,
            'nameservers_verified_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'deprovision_after' => 'immutable_datetime',
            'proxy_settings' => 'array',
            'cache_settings' => 'array',
            'cache_development_mode_until' => 'immutable_datetime',
            'security_settings' => 'array',
            'security_state_changed_at' => 'immutable_datetime',
            'active_edge_revision' => 'integer',
            'revision_changed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Domain $domain): void {
            if (! $domain->exists || $domain->isDirty('revision')) {
                $domain->revision_changed_at = now();
            }
        });
    }
}
