<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireDomainClaims;
use App\Enums\DomainLifecycleState;
use App\Jobs\CheckDomainNameservers;
use App\Jobs\CheckDomainRegistration;
use App\Models\Domain;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\NameserverResolver;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class DomainMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private const PAIR = ['ns1.cdn.example.net', 'ns2.cdn.example.net'];

    private function domain(string $name, array $attributes = []): Domain
    {
        $domain = new Domain;
        $domain->forceFill($attributes + [
            'name' => $name, 'display_name' => $name, 'revision' => 1,
            'lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()->subDay(),
            'delegation_nameservers' => self::PAIR,
        ])->save();

        return $domain;
    }

    /** Replaces the external parent lookup. A Throwable answer behaves like a failed lookup. */
    private function parentAnswers(array|\Throwable $answer): void
    {
        $this->app->instance(NameserverResolver::class, new class($answer) extends NameserverResolver
        {
            public function __construct(private array|\Throwable $answer) {}

            public function delegation(string $domain): array
            {
                return $this->answer instanceof \Throwable ? throw $this->answer : $this->answer;
            }
        });
    }

    private function runCheck(Domain $domain): Domain
    {
        app()->call([new CheckDomainNameservers($domain->id), 'handle']);

        return $domain->refresh();
    }

    public function test_dispatch_queues_only_due_checkable_domains_in_a_bounded_batch(): void
    {
        Queue::fake();
        $due = $this->domain('due.example.com', ['ns_check_next_at' => now()->subMinute()]);
        $later = $this->domain('later.example.com', ['ns_check_next_at' => now()->addHour()]);
        $expiredClaim = $this->domain('expired-claim.example.com', ['lifecycle_state' => DomainLifecycleState::PendingVerification, 'nameservers_verified_at' => null, 'claim_expires_at' => now()->subDay()]);
        $unassigned = $this->domain('unassigned.example.com', ['delegation_nameservers' => null]);
        $never = $this->domain('never.example.com');

        Artisan::call('cdnf:domains:dispatch-nameserver-checks', ['--limit' => 1]);

        Queue::assertPushed(CheckDomainNameservers::class, 1);
        Queue::assertPushed(CheckDomainNameservers::class, fn ($job) => $job->domainId === $never->id);
        $this->assertTrue($never->refresh()->ns_check_next_at->isFuture());

        Artisan::call('cdnf:domains:dispatch-nameserver-checks');
        Queue::assertPushed(CheckDomainNameservers::class, fn ($job) => $job->domainId === $due->id);
        Queue::assertNotPushed(CheckDomainNameservers::class, fn ($job) => in_array($job->domainId, [$later->id, $expiredClaim->id, $unassigned->id], true));
    }

    public function test_a_matching_delegation_resets_the_count_and_schedules_the_next_check(): void
    {
        $domain = $this->domain('match.example.com', ['ns_mismatch_count' => 2]);
        $this->parentAnswers(self::PAIR);

        $domain = $this->runCheck($domain);

        $this->assertSame('matched', $domain->ns_check_result);
        $this->assertSame(0, $domain->ns_mismatch_count);
        $this->assertSame(DomainLifecycleState::Active, $domain->lifecycle_state);
        $this->assertTrue($domain->ns_check_next_at->between(now()->addHours(11), now()->addHours(13)));
    }

    public function test_a_changed_delegation_unverifies_only_after_the_confirmed_checks_and_keeps_the_configuration(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain('moved.example.com');
        $domain->users()->attach($user);
        $this->parentAnswers(['ns1.other.example.org', 'ns2.other.example.org']);

        $domain = $this->runCheck($domain);
        $this->assertSame(1, $domain->ns_mismatch_count);
        $this->assertSame(DomainLifecycleState::Active, $domain->lifecycle_state);
        $domain = $this->runCheck($domain);
        $this->assertSame(DomainLifecycleState::Active, $domain->lifecycle_state);
        $revision = $domain->revision;

        $domain = $this->runCheck($domain);

        $this->assertSame(DomainLifecycleState::PendingVerification, $domain->lifecycle_state);
        $this->assertNotNull($domain->delegation_lost_at);
        $this->assertNotNull($domain->nameservers_verified_at, 'The verification record stays so claim expiry never removes the domain.');
        $this->assertSame($revision + 1, $domain->revision);
        $this->assertSame(['ns1.other.example.org', 'ns2.other.example.org'], $domain->ns_observed);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.delegation_lost', 'subject_id' => (string) $domain->id]);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_a_failed_lookup_never_counts_as_a_changed_delegation(): void
    {
        $domain = $this->domain('flaky.example.com', ['ns_mismatch_count' => 2]);
        $this->parentAnswers(new RuntimeException('resolver unreachable'));

        $domain = $this->runCheck($domain);

        $this->assertSame('unknown', $domain->ns_check_result);
        $this->assertSame(2, $domain->ns_mismatch_count);
        $this->assertSame(DomainLifecycleState::Active, $domain->lifecycle_state);
        $this->assertTrue($domain->ns_check_next_at->lte(now()->addMinutes(31)));
    }

    public function test_observe_only_mode_records_the_change_without_unverifying(): void
    {
        $domain = $this->domain('observed.example.com', ['ns_mismatch_count' => 2]);
        $settings = app(PlatformSettings::class);
        SystemSetting::query()->whereKey('domain_monitoring')->update(['values' => array_replace($settings->values('domain_monitoring'), ['nameserver_enforcement' => false])]);
        $this->app->forgetInstance(PlatformSettings::class);
        $this->parentAnswers(['ns1.other.example.org', 'ns2.other.example.org']);

        $domain = $this->runCheck($domain);

        $this->assertSame(DomainLifecycleState::Active, $domain->lifecycle_state);
        $this->assertNull($domain->delegation_lost_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.delegation_change_observed', 'subject_id' => (string) $domain->id]);
    }

    public function test_the_breaker_pauses_unverifying_when_many_checks_look_changed(): void
    {
        $key = 'domain-monitoring:'.now()->format('YmdH');
        Cache::put($key.':checked', 100, 3600);
        Cache::put($key.':mismatch', 20, 3600);
        $domain = $this->domain('breaker.example.com', ['ns_mismatch_count' => 2]);
        $this->parentAnswers(['ns1.other.example.org', 'ns2.other.example.org']);

        $domain = $this->runCheck($domain);

        $this->assertTrue(CheckDomainNameservers::breakerOpen());
        $this->assertSame(DomainLifecycleState::Active, $domain->lifecycle_state);
    }

    public function test_a_domain_that_lost_its_delegation_is_not_removed_by_claim_expiry(): void
    {
        $domain = $this->domain('kept.example.com', [
            'lifecycle_state' => DomainLifecycleState::PendingVerification, 'delegation_lost_at' => now()->subDays(10), 'claim_expires_at' => now()->subDays(9),
        ]);

        (new ExpireDomainClaims)->handle();

        $this->assertSame(DomainLifecycleState::PendingVerification, $domain->refresh()->lifecycle_state);
    }

    public function test_a_pending_domain_matching_again_goes_through_the_normal_verification_path(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('restored.example.com', [
            'lifecycle_state' => DomainLifecycleState::PendingVerification, 'delegation_lost_at' => now()->subHour(), 'ns_check_attempts' => 3,
        ]);
        $this->parentAnswers(self::PAIR);

        $domain = $this->runCheck($domain);

        $this->assertSame(4, $domain->ns_check_attempts);
        $this->assertDatabaseHas('operations', ['type' => 'domain.nameservers_verify', 'actor_id' => $admin->id, 'status' => 'pending']);
    }

    public function test_the_pending_backoff_starts_fast_then_slows_down(): void
    {
        $this->assertSame([1, 2, 5, 10, 15, 30, 60, 60, 360], array_map(CheckDomainNameservers::pendingDelay(...), [1, 2, 3, 4, 5, 6, 7, 29, 30]));
    }

    public function test_registration_expiry_is_recorded_and_reminders_are_sent_once_per_step(): void
    {
        Cache::forget('rdap-bootstrap');
        $user = User::factory()->create();
        $domain = $this->domain('expiring.example.com');
        $domain->users()->attach($user);
        $soon = ['events' => [['eventAction' => 'expiration', 'eventDate' => now()->addDays(20)->startOfDay()->toIso8601String()]]];
        $renewed = ['events' => [['eventAction' => 'expiration', 'eventDate' => now()->addYear()->toIso8601String()]]];
        Http::fake([
            'data.iana.org/*' => Http::response(['services' => [[['com'], ['https://rdap.registry.example/']]]]),
            'rdap.registry.example/domain/expiring.example.com' => Http::sequence()->push($soon)->push($soon)->push($renewed),
        ]);

        app()->call([new CheckDomainRegistration($domain->id), 'handle']);
        $domain->refresh();

        $this->assertSame(now()->addDays(20)->startOfDay()->timestamp, $domain->registration_expires_at->timestamp);
        $this->assertSame(30, $domain->registration_warned_days);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertTrue($domain->registration_next_check_at->lte(now()->addDay()->addMinute()), 'Near expiry is checked daily.');

        app()->call([new CheckDomainRegistration($domain->id), 'handle']);
        $this->assertSame(1, $user->notifications()->count(), 'The same step is not reminded twice.');

        app()->call([new CheckDomainRegistration($domain->id), 'handle']);
        $this->assertNull($domain->refresh()->registration_warned_days, 'A renewal clears the reminder history.');
    }

    public function test_a_tld_without_an_rdap_service_is_shown_as_unknown_and_rechecked_rarely(): void
    {
        Cache::forget('rdap-bootstrap');
        $domain = $this->domain('no-rdap.example.zz');
        Http::fake(['data.iana.org/*' => Http::response(['services' => [[['com'], ['https://rdap.registry.example/']]]])]);

        app()->call([new CheckDomainRegistration($domain->id), 'handle']);

        $domain->refresh();
        $this->assertNull($domain->registration_expires_at);
        $this->assertTrue($domain->registration_next_check_at->gte(now()->addDays(29)));
    }

    public function test_reminder_steps_follow_the_warning_window(): void
    {
        $this->assertNull(CheckDomainRegistration::reminderStep(45, 30));
        $this->assertSame(30, CheckDomainRegistration::reminderStep(30, 30));
        $this->assertSame(14, CheckDomainRegistration::reminderStep(10, 30));
        $this->assertSame(1, CheckDomainRegistration::reminderStep(1, 30));
        $this->assertSame(0, CheckDomainRegistration::reminderStep(-3, 30));
        $this->assertSame(7, CheckDomainRegistration::reminderStep(6, 10));
    }

    public function test_registration_dispatch_is_bounded_and_skips_unverified_domains(): void
    {
        Queue::fake();
        $verified = $this->domain('registered.example.com');
        $this->domain('unverified.example.com', ['lifecycle_state' => DomainLifecycleState::PendingVerification, 'nameservers_verified_at' => null]);

        Artisan::call('cdnf:domains:dispatch-registration-checks', ['--limit' => 10]);

        Queue::assertPushed(CheckDomainRegistration::class, 1);
        Queue::assertPushed(CheckDomainRegistration::class, fn ($job) => $job->domainId === $verified->id);
    }
}
