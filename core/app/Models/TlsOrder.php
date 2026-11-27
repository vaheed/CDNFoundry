<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

class TlsOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['private_key_ciphertext', 'csr_der'];

    /** This many customer-class failures inside the window point at the platform (a CA or DNS-01 outage), not at customers. */
    public const CUSTOMER_FAILURE_SURGE = 20;

    public const CUSTOMER_FAILURE_WINDOW_MINUTES = 15;

    public function challenges(): HasMany
    {
        return $this->hasMany(AcmeChallenge::class);
    }

    /**
     * Whose problem a failed order is. The certificate authority refusing the name because of a CAA record, or a
     * domain whose delegation was lost, is the customer's; everything else, including anything unrecognized, is
     * the platform's and is never hidden.
     */
    public static function failureClassFor(?Throwable $exception, ?Domain $domain): string
    {
        if ($domain?->delegation_lost_at !== null || preg_match('/\bCAA\b|rejectedIdentifier|caa record/i', (string) $exception?->getMessage()) === 1) {
            return 'customer';
        }

        return 'platform';
    }

    /** Failed orders that make the platform unhealthy: platform class, unclassified, or a customer-class surge. */
    public function scopePlatformFailed(Builder $query): Builder
    {
        $surge = self::customerFailureSurge();

        return $query->where('status', 'failed')->where(fn (Builder $class) => $class->whereNull('failure_class')->orWhere('failure_class', 'platform')
            ->when($surge, fn (Builder $any) => $any->orWhere('failure_class', 'customer')));
    }

    /** Failed orders that belong to a customer's domain; shown to its users and to administrators. */
    public function scopeCustomerFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed')->where('failure_class', 'customer');
    }

    public static function customerFailureSurge(): bool
    {
        return self::query()->customerFailed()->where('finished_at', '>=', now()->subMinutes(self::CUSTOMER_FAILURE_WINDOW_MINUTES))->count() >= self::CUSTOMER_FAILURE_SURGE;
    }

    protected function casts(): array
    {
        return [
            'names' => 'array', 'authorization_urls' => 'array',
            'private_key_ciphertext' => 'encrypted',
            'available_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'next_poll_at' => 'immutable_datetime',
            'alerted_at' => 'immutable_datetime',
        ];
    }
}
