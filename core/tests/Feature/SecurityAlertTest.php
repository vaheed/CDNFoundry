<?php

namespace Tests\Feature;

use App\Console\Commands\NotifySecurityMitigations;
use App\Jobs\DeliverNotification;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Notifications\NotificationEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityAlertTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDomain(string $name = 'alert.example'): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    /** One hourly row as the edge reports write it: events counted in per-minute slots. */
    private function report(Domain $domain, string $reason, int $count, int $minutesAgo = 1): void
    {
        SecurityEvent::query()->create([
            'domain_id' => $domain->id, 'reason_code' => $reason, 'bucket_at' => now()->subMinutes($minutesAgo)->startOfHour(), 'occurred_at' => now()->subMinutes($minutesAgo),
            'details' => ['count' => $count, 'reported' => $count, 'minutes' => [(string) intdiv(now()->subMinutes($minutesAgo)->timestamp, 60) => $count]],
        ]);
    }

    private function channel(): NotificationChannel
    {
        $channel = new NotificationChannel(['type' => 'telegram', 'name' => 'Ops', 'destination_hint' => 'Telegram chat 1']);
        $channel->storeSecrets(['bot_token' => '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'chat_id' => '1']);
        $channel->save();

        return $channel;
    }

    public function test_the_alert_is_set_read_and_removed_by_the_domains_users_only(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/alert";
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.alert', null)->assertJsonPath('data.cooldown_minutes', 60);
        $this->actingAs($user)->putJson($url, ['threshold' => 250, 'period_minutes' => 10])->assertOk()->assertJsonPath('data.alert.threshold', 250);
        $this->assertSame(['threshold' => 250, 'period_minutes' => 10], $domain->refresh()->security_alert);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.alert_updated', 'subject_id' => (string) $domain->id]);
        foreach ([['threshold' => 0, 'period_minutes' => 5], ['threshold' => 1000001, 'period_minutes' => 5], ['threshold' => 10, 'period_minutes' => 20], ['threshold' => 'many', 'period_minutes' => 5], ['period_minutes' => 5]] as $bad) {
            $this->actingAs($user)->putJson($url, $bad)->assertUnprocessable();
        }
        $this->assertSame(250, $domain->refresh()->security_alert['threshold']);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->putJson($url, ['threshold' => 1, 'period_minutes' => 5])->assertNotFound();
        $this->actingAs($stranger)->getJson($url)->assertNotFound();
        $this->actingAs($user)->deleteJson($url)->assertOk()->assertJsonPath('data.alert', null);
        $this->assertNull($domain->refresh()->security_alert);
    }

    public function test_an_attack_sends_one_alert_however_many_requests_it_blocks(): void
    {
        Queue::fake();
        Cache::flush();
        $this->channel();
        [, $domain] = $this->ownedDomain();
        $domain->update(['security_alert' => ['threshold' => 100, 'period_minutes' => 5]]);

        // Below the threshold nothing is sent; reports of different reasons add up inside the period.
        $this->report($domain, 'custom_rule_block', 40);
        $this->artisan('cdnf:notifications:security-mitigations')->assertSuccessful();
        Queue::assertNotPushed(DeliverNotification::class);
        $this->report($domain, 'waf_block', 70);
        $this->artisan('cdnf:notifications:security-mitigations')->assertSuccessful();
        Queue::assertPushed(DeliverNotification::class, 1);
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => $job->message['event'] === 'security.mitigations_high' && $job->message['domain'] === 'alert.example' && str_contains($job->message['body'], '110 requests'));

        // The attack goes on: more blocked requests, more runs, still no second alert.
        $this->report($domain, 'waf_block', 5000, 0);
        foreach (range(1, 5) as $_) {
            $this->artisan('cdnf:notifications:security-mitigations')->assertSuccessful();
        }
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_only_mitigations_inside_the_period_count(): void
    {
        Queue::fake();
        Cache::flush();
        $this->channel();
        [, $domain] = $this->ownedDomain();
        $domain->update(['security_alert' => ['threshold' => 100, 'period_minutes' => 5]]);
        $other = Domain::query()->create(['name' => 'quiet.example', 'display_name' => 'quiet', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $other->update(['security_alert' => ['threshold' => 100, 'period_minutes' => 5]]);

        $this->report($domain, 'custom_rule_block', 500, 9);               // older than the period
        $this->report($domain, 'custom_rule_log', 500);                   // logged by a rule, never blocked
        $this->report($domain, 'waf_rule_logged', 500);
        $this->report($other, 'custom_rule_block', 99);                   // one short of its threshold, and not this domain's events
        $noAlert = Domain::query()->create(['name' => 'unset.example', 'display_name' => 'unset', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $this->report($noAlert, 'custom_rule_block', 9999);
        $this->artisan('cdnf:notifications:security-mitigations')->assertSuccessful();
        Queue::assertNotPushed(DeliverNotification::class);

        // A platform rule's blocks count, and each domain alerts on its own.
        $this->report($domain, 'platform_rule_block', 100);
        $this->report($other, 'custom_rule_rate_limited', 1);
        $this->artisan('cdnf:notifications:security-mitigations')->assertSuccessful();
        Queue::assertPushed(DeliverNotification::class, 2);
    }

    public function test_the_alert_is_a_domain_event_and_is_sent_again_after_the_cooldown(): void
    {
        Queue::fake();
        Cache::flush();
        $this->channel();
        [, $domain] = $this->ownedDomain();
        $domain->update(['security_alert' => ['threshold' => 10, 'period_minutes' => 5]]);
        $this->report($domain, 'custom_rule_block', 50);
        $this->artisan('cdnf:notifications:security-mitigations');
        $this->travel(61)->minutes();
        $this->report($domain, 'custom_rule_block', 50, 0);
        $this->artisan('cdnf:notifications:security-mitigations');
        Queue::assertPushed(DeliverNotification::class, 2);
        $this->assertArrayHasKey('security.mitigations_high', NotificationEvents::forDomainUsers());
        $this->assertInstanceOf(NotifySecurityMitigations::class, app(NotifySecurityMitigations::class));
    }
}
