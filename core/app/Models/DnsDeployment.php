<?php

namespace App\Models;

use App\Exceptions\ZoneContentRejectedException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

#[Fillable(['domain_id', 'dns_cluster_id', 'desired_revision', 'deployed_revision', 'status', 'active_checksum', 'active_rrsets', 'last_error', 'attempts', 'last_attempted_at', 'deployed_at', 'verified_at', 'tombstone', 'deprovisioned_at', 'failure_class', 'first_failed_at'])]
class DnsDeployment extends Model
{
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(DnsCluster::class, 'dns_cluster_id');
    }

    /** A platform failure must outlast this many minutes before it degrades platform health, so a retry window does not flap it. */
    public const PLATFORM_FAILURE_GRACE_MINUTES = 2;

    /** A pending row is planned work (a takeover or a new revision) until it is older than this; the job retries for 30 minutes. */
    public const PLANNED_PENDING_GRACE_MINUTES = 10;

    /** This many customer-content rejections inside the window point at the platform (a validator and PowerDNS disagreeing), not at customers. */
    public const CUSTOMER_REJECTION_SURGE = 20;

    public const CUSTOMER_REJECTION_WINDOW_MINUTES = 15;

    /**
     * Deployments that make the platform unhealthy: a failure of the platform's own provisioning that has lasted
     * past its grace period, a failed retirement, a pending row that is no longer planned work, and a surge of
     * customer-content rejections. A deleted domain keeps a pending tombstone until its retirement date, which is
     * the plan and not drift; only a failed retirement counts. Customer-content rejections and delegation states
     * are customer health, see scopeCustomerRejected.
     */
    public function scopeDrifted(Builder $query): Builder
    {
        $surge = self::customerRejectionSurge();

        return $query->where(fn (Builder $drifted) => $drifted
            ->where(fn (Builder $failed) => $failed->where('status', 'failed')
                ->where(fn (Builder $platform) => $platform->whereNull('failure_class')->orWhere('failure_class', 'platform')
                    ->when($surge, fn (Builder $any) => $any->orWhere('failure_class', 'customer_content')))
                ->where(fn (Builder $aged) => $aged->whereNull('first_failed_at')->orWhere('first_failed_at', '<=', now()->subMinutes(self::PLATFORM_FAILURE_GRACE_MINUTES))))
            ->orWhere(fn (Builder $pending) => $pending->where('status', 'pending')->where('tombstone', false)
                ->where('updated_at', '<=', now()->subMinutes(self::PLANNED_PENDING_GRACE_MINUTES))));
    }

    /** Zones PowerDNS refused because of what the customer put in them. They are shown to the domain's users and to administrators. */
    public function scopeCustomerRejected(Builder $query): Builder
    {
        return $query->where('status', 'failed')->where('failure_class', 'customer_content')->where('tombstone', false);
    }

    public static function customerRejectionSurge(): bool
    {
        return self::query()->customerRejected()->where('first_failed_at', '>=', now()->subMinutes(self::CUSTOMER_REJECTION_WINDOW_MINUTES))->count() >= self::CUSTOMER_REJECTION_SURGE;
    }

    /** What a failed attempt records: the class, and the first failure's time, which later retries keep. */
    public function failureAttributes(Throwable $exception): array
    {
        return [
            'status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            'failure_class' => $exception instanceof ZoneContentRejectedException ? 'customer_content' : 'platform',
            'first_failed_at' => $this->first_failed_at ?? now(),
        ];
    }

    protected function casts(): array
    {
        return ['active_rrsets' => 'array', 'tombstone' => 'boolean', 'last_attempted_at' => 'immutable_datetime', 'deployed_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'first_failed_at' => 'immutable_datetime', 'deprovisioned_at' => 'immutable_datetime'];
    }
}
