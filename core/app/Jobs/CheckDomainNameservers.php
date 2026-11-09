<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\EdgeArtifact;
use App\Models\Operation;
use App\Models\User;
use App\Support\DomainNameserverVerification;
use App\Support\NameserverResolver;
use App\Support\PlatformSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One scheduled delegation check for a pending or active domain. A pending claim that now matches goes
 * through the normal verification path. An active domain whose delegation stays different for several
 * checks is un-verified, and a lookup failure never counts as a difference.
 */
class CheckDomainNameservers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** Minutes between checks of a pending domain, by checks made so far, then hourly for a day, then every six hours. */
    private const PENDING_BACKOFF_MINUTES = [1, 2, 5, 10, 15, 30];

    public function __construct(public int $domainId)
    {
        $this->onQueue('verification');
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(NameserverResolver $resolver, DomainNameserverVerification $verification, PlatformSettings $settings): void
    {
        $domain = Domain::query()->find($this->domainId);
        if ($domain === null || ! self::checkable($domain)) {
            return;
        }
        $expected = $domain->assignedNameservers();
        sort($expected);
        try {
            $observed = $resolver->withParentCache()->delegation($domain->name);
            $result = $observed === $expected ? 'matched' : 'mismatch';
        } catch (Throwable) {
            $observed = null;
            $result = 'unknown';
        }
        if ($result !== 'unknown') {
            self::countOutcome($result === 'mismatch');
        }

        if ($domain->lifecycle_state === DomainLifecycleState::Active) {
            $this->recordActive($domain, $result, $observed, $settings);

            return;
        }
        $this->recordPending($domain, $result, $observed, $verification);
    }

    /** Whether a scheduled check still applies to the domain's current state. */
    public static function checkable(Domain $domain): bool
    {
        if ($domain->delegation_nameservers === null || count($domain->assignedNameservers()) < 2) {
            return false;
        }

        return match ($domain->lifecycle_state) {
            DomainLifecycleState::Active => $domain->nameservers_verified_at !== null,
            DomainLifecycleState::PendingVerification => $domain->nameservers_verified_at !== null || $domain->claim_expires_at?->isFuture() === true,
            default => false,
        };
    }

    /** @param list<string>|null $observed */
    private function recordActive(Domain $domain, string $result, ?array $observed, PlatformSettings $settings): void
    {
        $interval = $settings->integer('domain_monitoring', 'active_check_hours') * 60;
        $confirmations = $settings->integer('domain_monitoring', 'mismatch_confirmations');
        $spacing = $settings->integer('domain_monitoring', 'confirmation_spacing_minutes');
        $base = ['ns_checked_at' => now(), 'ns_check_result' => $result, 'ns_observed' => $observed === null ? null : json_encode($observed)];

        if ($result === 'unknown') {
            $this->save($domain, $base + ['ns_check_next_at' => now()->addMinutes(min($interval, 30))]);

            return;
        }
        if ($result === 'matched') {
            $this->save($domain, $base + ['ns_mismatch_count' => 0, 'ns_check_next_at' => now()->addMinutes($interval)]);

            return;
        }
        $count = $domain->ns_mismatch_count + 1;
        if ($count < $confirmations) {
            $this->save($domain, $base + ['ns_mismatch_count' => $count, 'ns_check_next_at' => now()->addMinutes($spacing)]);

            return;
        }
        if (! (bool) $settings->get('domain_monitoring', 'nameserver_enforcement') || self::breakerOpen()) {
            // Observe only, or a mass change that looks like our own resolver failing: record it and keep the domain active.
            if ($count === $confirmations) {
                AuditLog::record(null, 'domain.delegation_change_observed', $domain, ['observed' => $observed, 'expected' => $domain->assignedNameservers()]);
            }
            $this->save($domain, $base + ['ns_mismatch_count' => $count, 'ns_check_next_at' => now()->addMinutes($spacing)]);

            return;
        }
        $this->unverify($domain, $base, $count, $observed);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  list<string>|null  $observed
     */
    private function unverify(Domain $domain, array $base, int $count, ?array $observed): void
    {
        DB::transaction(function () use ($domain, $base, $count, $observed): void {
            $locked = Domain::query()->lockForUpdate()->find($domain->id);
            if ($locked === null || $locked->lifecycle_state !== DomainLifecycleState::Active) {
                return;
            }
            // nameservers_verified_at stays: claim expiry only removes never-verified claims, so a lost delegation never deprovisions the domain.
            $locked->forceFill(['ns_observed' => $observed] + $base + [
                'lifecycle_state' => DomainLifecycleState::PendingVerification,
                'delegation_lost_at' => now(),
                'ns_mismatch_count' => $count,
                'ns_check_attempts' => 0,
                'ns_check_next_at' => now()->addMinutes(1),
                'revision' => $locked->revision + 1,
            ])->save();
            AuditLog::record(null, 'domain.delegation_lost', $locked, ['revision' => $locked->revision, 'observed' => $observed, 'expected' => $locked->assignedNameservers()]);
            if ($locked->dnsRecords()->where('mode', 'proxied')->exists() || EdgeArtifact::query()->where('domain_id', $locked->id)->exists()) {
                Operation::coalesceDomain('edge.domain_reconcile', $locked->id);
                ReconcileEdgeDomain::dispatch($locked->id)->afterCommit();
            }
        });
        $fresh = Domain::query()->find($domain->id);
        if ($fresh?->delegation_lost_at !== null) {
            self::notifyUsers($fresh, 'Nameservers changed', "{$fresh->name} no longer uses your assigned nameservers, so it is unverified. Set them again at your registrar and it verifies on its own.");
        }
    }

    /** @param list<string>|null $observed */
    private function recordPending(Domain $domain, string $result, ?array $observed, DomainNameserverVerification $verification): void
    {
        $attempts = $domain->ns_check_attempts + 1;
        $base = ['ns_checked_at' => now(), 'ns_check_result' => $result, 'ns_observed' => $observed === null ? null : json_encode($observed), 'ns_check_attempts' => $attempts];
        $this->save($domain, $base + ['ns_check_next_at' => now()->addMinutes(self::pendingDelay($attempts))]);
        if ($result !== 'matched') {
            return;
        }
        $actor = self::actorFor($domain);
        if ($actor === null) {
            return;
        }
        try {
            $verification->queue($domain, $actor, null, automatic: true);
        } catch (Throwable) {
            // The next scheduled check tries again.
        }
    }

    public static function pendingDelay(int $attempts): int
    {
        return match (true) {
            $attempts <= count(self::PENDING_BACKOFF_MINUTES) => self::PENDING_BACKOFF_MINUTES[$attempts - 1],
            $attempts <= 29 => 60,
            default => 360,
        };
    }

    /** The user whose authority a scheduled verification runs under: an assigned user, else an administrator. */
    private static function actorFor(Domain $domain): ?User
    {
        $assigned = self::assignedUsers($domain)->first(fn (User $user): bool => ! $user->isDisabled());

        return $assigned ?? User::query()->where('type', 'admin')->orderBy('id')->get()->first(fn (User $user): bool => ! $user->isDisabled());
    }

    /** @return Collection<int, User> */
    private static function assignedUsers(Domain $domain): Collection
    {
        return User::query()->whereIn('id', $domain->users()->pluck('users.id'))->orderBy('id')->get();
    }

    /** @param array<string, mixed> $values */
    private function save(Domain $domain, array $values): void
    {
        Domain::query()->whereKey($domain->id)->update($values);
    }

    private static function countOutcome(bool $mismatch): void
    {
        $key = 'domain-monitoring:'.now()->format('YmdH');
        Cache::add($key.':checked', 0, now()->addHours(3));
        Cache::increment($key.':checked');
        if ($mismatch) {
            Cache::add($key.':mismatch', 0, now()->addHours(3));
            Cache::increment($key.':mismatch');
        }
    }

    /**
     * Five percent of at least fifty definite checks in the hour showing a change is far more likely our
     * own resolver or network failing than that many customers moving at once, so un-verifying pauses.
     */
    public static function breakerOpen(): bool
    {
        $key = 'domain-monitoring:'.now()->format('YmdH');
        $checked = (int) Cache::get($key.':checked', 0);

        return $checked >= 50 && ((int) Cache::get($key.':mismatch', 0)) * 20 >= $checked;
    }

    public static function notifyUsers(Domain $domain, string $title, string $body, bool $good = false): void
    {
        foreach (self::assignedUsers($domain) as $user) {
            if (! $user->isDisabled()) {
                $notice = Notification::make()->title($title)->body($body);
                ($good ? $notice->success() : $notice->warning())->sendToDatabase($user);
            }
        }
    }
}
