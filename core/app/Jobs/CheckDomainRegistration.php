<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use App\Support\PlatformSettings;
use App\Support\RegistrationExpiryLookup;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One registration expiry lookup. It only records the date and reminds users; it never changes a domain's state. */
class CheckDomainRegistration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId)
    {
        $this->onQueue('verification');
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(RegistrationExpiryLookup $lookup, PlatformSettings $settings): void
    {
        $domain = Domain::query()->find($this->domainId);
        if ($domain === null || $domain->nameservers_verified_at === null || $domain->lifecycle_state === DomainLifecycleState::Deprovisioning) {
            return;
        }
        $warning = $settings->integer('domain_monitoring', 'registration_warning_days');
        $result = $lookup->lookup($domain->name);

        if ($result['status'] === 'unsupported') {
            Domain::query()->whereKey($domain->id)->update(['registration_checked_at' => now(), 'registration_next_check_at' => now()->addDays(30)]);

            return;
        }
        if ($result['status'] === 'unknown') {
            Domain::query()->whereKey($domain->id)->update(['registration_next_check_at' => now()->addDay()]);

            return;
        }
        $expires = $result['expires'];
        $daysLeft = Domain::daysUntil($expires);
        $nearExpiry = $daysLeft <= $warning;
        $warned = $domain->registration_warned_days;
        $step = self::reminderStep($daysLeft, $warning);
        $notify = $step !== null && ($warned === null || $step < $warned);

        Domain::query()->whereKey($domain->id)->update([
            'registration_expires_at' => $expires,
            'registration_checked_at' => now(),
            'registration_next_check_at' => now()->addDays($nearExpiry ? 1 : $settings->integer('domain_monitoring', 'registration_check_days')),
            // A renewal clears the reminder history so the next approach to expiry reminds again.
            'registration_warned_days' => $nearExpiry ? ($notify ? $step : $warned) : null,
        ]);
        if ($notify) {
            $message = $daysLeft <= 0
                ? "The registration of {$domain->name} has expired. Renew it at your registrar to keep it working."
                : "The registration of {$domain->name} expires in {$daysLeft} days. Renew it at your registrar.";
            CheckDomainNameservers::notifyUsers($domain, 'Domain registration expires soon', $message);
        }
    }

    /** The smallest reminder step (the warning window, then 14, 7, 1, and 0 days) that the days left have reached. */
    public static function reminderStep(int $daysLeft, int $warning): ?int
    {
        $steps = collect([$warning, 14, 7, 1, 0])->filter(fn (int $step): bool => $step <= $warning)->unique()->sortDesc()->values();

        return $steps->filter(fn (int $step): bool => $daysLeft <= $step)->min();
    }
}
