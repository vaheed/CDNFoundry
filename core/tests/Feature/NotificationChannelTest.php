<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Jobs\TestNotificationChannel;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\Operation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Notifications\EventPublisher;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class NotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    private const TELEGRAM = ['type' => 'telegram', 'name' => 'Ops chat', 'bot_token' => '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'chat_id' => '-1001234567890'];

    private const DISCORD_URL = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz0123456789';

    private const SLACK_URL = 'https://hooks.slack.com/services/T0000000/B0000000/abcdefghijklmnopqrstuvwx';

    private function enableUserChannels(bool $on = true): void
    {
        $row = SystemSetting::query()->findOrFail('notifications');
        $row->update(['values' => [...$row->values, 'domain_user_channels' => $on]]);
        app()->forgetInstance(PlatformSettings::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['type' => 'admin']);
    }

    public function test_an_administrator_adds_every_channel_type_and_secrets_are_never_returned(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        foreach ([self::TELEGRAM, ['type' => 'discord', 'name' => 'Discord', 'webhook_url' => self::DISCORD_URL], ['type' => 'slack', 'name' => 'Slack', 'webhook_url' => self::SLACK_URL]] as $payload) {
            $response = $this->postJson('/api/admin/notification-channels', $payload)->assertCreated();
            $this->assertStringNotContainsString('AAAAAAAA', $response->getContent());
            $this->assertStringNotContainsString('abcdefghijklmnop', $response->getContent());
            $response->assertJsonPath('data.status', 'active')->assertJsonPath('data.events', null);
        }
        $webhook = $this->postJson('/api/admin/notification-channels', ['type' => 'webhook', 'name' => 'Pager', 'webhook_url' => 'https://93.184.216.34/hook'])->assertCreated();
        $this->assertSame(48, strlen($webhook->json('meta.signing_secret')));
        $list = $this->getJson('/api/admin/notification-channels')->assertOk();
        $this->assertStringNotContainsString('signing_secret', $list->getContent());
        $this->assertStringNotContainsString('123456789:AAAA', NotificationChannel::query()->firstOrFail()->secret_ciphertext);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification_channel.created']);
    }

    public function test_provider_urls_must_be_https_on_the_providers_own_host(): void
    {
        $this->actingAs($this->admin());
        foreach ([
            ['type' => 'discord', 'name' => 'x', 'webhook_url' => 'http://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz0123456789'],
            ['type' => 'discord', 'name' => 'x', 'webhook_url' => 'https://evil.example/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz0123456789'],
            ['type' => 'slack', 'name' => 'x', 'webhook_url' => 'https://hooks.slack.com.evil.example/services/T0000000/B0000000/abcdefghijklmnopqrstuvwx'],
            ['type' => 'slack', 'name' => 'x', 'webhook_url' => 'https://user@hooks.slack.com/services/T0000000/B0000000/abcdefghijklmnopqrstuvwx'],
        ] as $payload) {
            $this->postJson('/api/admin/notification-channels', $payload)->assertUnprocessable()->assertJsonValidationErrors('webhook_url');
        }
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_the_generic_webhook_refuses_loopback_private_link_local_and_metadata_destinations(): void
    {
        $this->actingAs($this->admin());
        foreach (['http://127.0.0.1/hook', 'http://10.0.0.5/hook', 'https://169.254.169.254/latest', 'http://[::1]/hook', 'http://192.168.1.10:8080/x', 'ftp://93.184.216.34/x', 'https://user:pw@93.184.216.34/x'] as $url) {
            $this->postJson('/api/admin/notification-channels', ['type' => 'webhook', 'name' => 'x', 'webhook_url' => $url])->assertUnprocessable();
        }
        // A narrow private allow-list can admit an ordinary private address but never loopback.
        $row = SystemSetting::query()->findOrFail('origin_safety');
        $row->update(['values' => [...$row->values, 'private_origin_allowlist' => ['10.20.0.0/16']]]);
        app()->forgetInstance(PlatformSettings::class);
        $this->postJson('/api/admin/notification-channels', ['type' => 'webhook', 'name' => 'lab', 'webhook_url' => 'http://10.20.0.5/hook'])->assertCreated();
        $this->postJson('/api/admin/notification-channels', ['type' => 'webhook', 'name' => 'x', 'webhook_url' => 'http://127.0.0.1/hook'])->assertUnprocessable();
    }

    public function test_domain_users_cannot_add_channels_until_the_administrator_switch_is_on_and_never_a_webhook(): void
    {
        $user = User::factory()->create();
        $payload = [...self::TELEGRAM, 'events' => ['certificate.failed']];
        $this->actingAs($user)->postJson('/api/notification-channels', $payload)->assertForbidden();

        $this->enableUserChannels();
        $this->actingAs($user)->postJson('/api/notification-channels', $payload)->assertCreated()->assertJsonPath('data.events', ['certificate.failed']);
        $this->postJson('/api/notification-channels', ['type' => 'webhook', 'name' => 'x', 'webhook_url' => 'https://93.184.216.34/h', 'events' => ['certificate.failed']])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['alert.firing']])->assertUnprocessable()->assertJsonValidationErrors('events.0');
        $this->postJson('/api/notification-channels', [...self::TELEGRAM])->assertUnprocessable()->assertJsonValidationErrors('events');
        $this->actingAs($this->admin())->postJson('/api/notification-channels', $payload)->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/notification-channels')->assertForbidden();
    }

    public function test_a_user_sees_and_manages_only_their_own_channels(): void
    {
        $this->enableUserChannels();
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->actingAs($other)->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['certificate.failed']])->json('data.id');
        $this->actingAs($mine)->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['certificate.failed']])->assertCreated();

        $this->actingAs($mine)->getJson('/api/notification-channels')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/notification-channels/{$theirs}")->assertForbidden();
        $this->postJson("/api/notification-channels/{$theirs}/test")->assertForbidden();
        $this->postJson("/api/notification-channels/{$theirs}/resume")->assertForbidden();
        $this->assertDatabaseHas('notification_channels', ['id' => $theirs]);
    }

    public function test_channel_counts_are_bounded(): void
    {
        $this->enableUserChannels();
        $row = SystemSetting::query()->findOrFail('notifications');
        $row->update(['values' => [...$row->values, 'max_channels_per_user' => 2]]);
        app()->forgetInstance(PlatformSettings::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['certificate.failed']])->assertCreated();
        }
        $this->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['certificate.failed']])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_creation_is_idempotent_and_replay_never_repeats_the_signing_secret(): void
    {
        $this->actingAs($this->admin());
        $payload = ['type' => 'webhook', 'name' => 'Pager', 'webhook_url' => 'https://93.184.216.34/hook'];
        $first = $this->postJson('/api/admin/notification-channels', $payload, ['Idempotency-Key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'])->assertCreated();
        $replay = $this->postJson('/api/admin/notification-channels', $payload, ['Idempotency-Key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        $replay->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertStringNotContainsString($first->json('meta.signing_secret'), $replay->getContent());
        $this->assertSame(1, NotificationChannel::query()->count());
        $this->postJson('/api/admin/notification-channels', [...$payload, 'name' => 'Different'], ['Idempotency-Key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'])->assertConflict();
    }

    public function test_events_reach_administrator_channels_and_only_assigned_domain_users(): void
    {
        Queue::fake();
        $this->enableUserChannels();
        $admin = $this->admin();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $mine = Domain::query()->create(['name' => 'alice.example', 'display_name' => 'Alice', 'revision' => 1, 'lifecycle_state' => 'active']);
        $other = Domain::query()->create(['name' => 'bob.example', 'display_name' => 'Bob', 'revision' => 1, 'lifecycle_state' => 'active']);
        $mine->users()->attach($alice->id);
        $other->users()->attach($bob->id);
        $this->actingAs($admin)->postJson('/api/admin/notification-channels', [...self::TELEGRAM, 'name' => 'Admin'])->assertCreated();
        $aliceChannel = $this->actingAs($alice)->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['certificate.failed']])->json('data.id');
        $bobChannel = $this->actingAs($bob)->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['certificate.failed']])->json('data.id');

        $this->assertSame(2, EventPublisher::publish('certificate.failed', $mine, 'Failed', 'x'));
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => $job->channelId === $aliceChannel && $job->message['domain'] === 'alice.example');
        Queue::assertNotPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => $job->channelId === $bobChannel);
        Queue::assertPushedOn('notifications', DeliverNotification::class);
        // An event the user did not pick, and a platform event, never reach a user channel.
        $this->assertSame(1, EventPublisher::publish('certificate.issued', $mine, 'Issued'));
        $this->assertSame(1, EventPublisher::publish('alert.firing', null, 'Alert'));
    }

    public function test_turning_the_switch_off_stops_user_channels_and_keeps_them(): void
    {
        Http::fake();
        $this->enableUserChannels();
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'off.example', 'display_name' => 'Off', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $id = $this->actingAs($user)->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['certificate.failed']])->json('data.id');
        $this->enableUserChannels(false);

        Queue::fake();
        $this->assertSame(0, EventPublisher::publish('certificate.failed', $domain, 'Failed'));
        // A message queued before the switch changed is dropped when it runs.
        (new DeliverNotification($id, ['event' => 'certificate.failed', 'title' => 'Failed', 'body' => '', 'domain' => 'off.example', 'occurred_at' => now()->toIso8601String()]))->handle(app(PlatformSettings::class));
        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_channels', ['id' => $id, 'status' => 'active']);
    }

    public function test_each_provider_gets_its_own_request_shape(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $message = ['event' => 'certificate.failed', 'title' => 'Failed', 'body' => 'It broke', 'domain' => 'a.example', 'occurred_at' => now()->toIso8601String()];
        $this->actingAs($this->admin());
        $ids = [];
        foreach ([self::TELEGRAM, ['type' => 'discord', 'name' => 'd', 'webhook_url' => self::DISCORD_URL], ['type' => 'slack', 'name' => 's', 'webhook_url' => self::SLACK_URL], ['type' => 'webhook', 'name' => 'w', 'webhook_url' => 'https://93.184.216.34/hook']] as $payload) {
            $ids[] = $this->postJson('/api/admin/notification-channels', $payload)->json('data.id');
        }
        foreach ($ids as $id) {
            (new DeliverNotification($id, $message))->handle(app(PlatformSettings::class));
        }
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.telegram.org/bot123456789:') && $request['chat_id'] === '-1001234567890' && str_contains($request['text'], 'a.example'));
        Http::assertSent(fn ($request): bool => $request->url() === self::DISCORD_URL && $request['allowed_mentions'] === ['parse' => []]);
        Http::assertSent(fn ($request): bool => $request->url() === self::SLACK_URL && str_contains($request['text'], 'It broke'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://93.184.216.34/hook' && str_starts_with($request->header('X-CDNFoundry-Signature')[0], 'sha256=') && $request['domain'] === 'a.example');
        $this->assertSame(4, NotificationChannel::query()->whereNotNull('last_success_at')->count());
    }

    public function test_repeats_beyond_the_per_minute_limit_merge_into_one_digest(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        Queue::fake();
        Cache::flush();
        RateLimiter::clear('x');
        $row = SystemSetting::query()->findOrFail('notifications');
        $row->update(['values' => [...$row->values, 'channel_messages_per_minute' => 1]]);
        app()->forgetInstance(PlatformSettings::class);
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/admin/notification-channels', self::TELEGRAM)->json('data.id');
        $message = fn (string $title): array => ['event' => 'certificate.failed', 'title' => $title, 'body' => '', 'domain' => null, 'occurred_at' => now()->toIso8601String()];

        foreach (['one', 'two', 'three'] as $title) {
            (new DeliverNotification($id, $message($title)))->handle(app(PlatformSettings::class));
        }
        Http::assertSentCount(1);
        Queue::assertPushed(DeliverNotification::class, 1);
        $digest = null;
        Queue::assertPushed(DeliverNotification::class, function (DeliverNotification $job) use (&$digest): bool {
            $digest = $job;

            return $job->message['event'] === 'digest';
        });
        $digest->handle(app(PlatformSettings::class));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), '2 more notifications') && str_contains($request['text'], 'two') && str_contains($request['text'], 'three'));
    }

    public function test_a_channel_that_keeps_failing_is_paused_with_its_reason_and_can_be_resumed(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('no', 500)]);
        $row = SystemSetting::query()->findOrFail('notifications');
        $row->update(['values' => [...$row->values, 'pause_after_failures' => 3]]);
        app()->forgetInstance(PlatformSettings::class);
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/admin/notification-channels', self::TELEGRAM)->json('data.id');
        $job = new DeliverNotification($id, ['event' => 'certificate.failed', 'title' => 'x', 'body' => '', 'domain' => null, 'occurred_at' => now()->toIso8601String()]);
        $thrown = 0;
        for ($i = 0; $i < 3; $i++) {
            try {
                $job->handle(app(PlatformSettings::class));
            } catch (\Throwable $exception) {
                $thrown++;
                $job->failed($exception);
            }
        }
        $this->assertSame(3, $thrown);
        $this->getJson('/api/admin/notification-channels')->assertJsonPath('data.0.status', 'paused')->assertJsonPath('data.0.consecutive_failures', 3);
        $this->assertStringContainsString('Telegram answered HTTP 500', NotificationChannel::query()->findOrFail($id)->paused_reason);
        $this->assertSame(0, EventPublisher::publish('certificate.failed', null, 'x'));

        $this->postJson("/api/admin/notification-channels/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(1, EventPublisher::publish('certificate.failed', null, 'x'));
    }

    public function test_a_test_message_is_an_operation_its_owner_can_read(): void
    {
        Queue::fake();
        $this->enableUserChannels();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->actingAs($user)->postJson('/api/notification-channels', [...self::TELEGRAM, 'events' => ['certificate.failed']])->json('data.id');
        $operationId = $this->postJson("/api/notification-channels/{$id}/test")->assertStatus(202)->json('data.operation_id');
        Queue::assertPushedOn('notifications', TestNotificationChannel::class);
        $this->getJson("/api/operations/{$operationId}")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->actingAs($other)->getJson("/api/operations/{$operationId}")->assertForbidden();

        Http::fake(['*' => Http::sequence()->push(['ok' => true])->push('bad', 401)]);
        (new TestNotificationChannel($operationId))->handle();
        $this->assertSame('succeeded', Operation::query()->findOrFail($operationId)->status);

        $failed = $this->actingAs($user)->postJson("/api/notification-channels/{$id}/test")->json('data.operation_id');
        (new TestNotificationChannel($failed))->handle();
        $this->assertSame('failed', Operation::query()->findOrFail($failed)->status);
        $this->assertStringContainsString('401', Operation::query()->findOrFail($failed)->error);
        $this->assertSame(0, NotificationChannel::query()->findOrFail($id)->consecutive_failures, 'a test never pauses a channel');
    }

    public function test_deleting_a_channel_is_audited_and_removes_it(): void
    {
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/admin/notification-channels', self::TELEGRAM)->json('data.id');
        $this->deleteJson("/api/admin/notification-channels/{$id}")->assertOk();
        $this->assertDatabaseMissing('notification_channels', ['id' => $id]);
        $this->assertSame(1, AuditLog::query()->where('action', 'notification_channel.deleted')->count());
    }
}
