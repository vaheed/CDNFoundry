<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlertmanagerReceiverTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $name = 'EdgeHeartbeatStale', string $status = 'firing'): array
    {
        return ['status' => $status, 'alerts' => [['status' => $status, 'labels' => ['alertname' => $name, 'team' => 'noc'], 'annotations' => ['summary' => 'Edge a is stale']]]];
    }

    private function channel(): NotificationChannel
    {
        $channel = new NotificationChannel(['type' => 'telegram', 'name' => 'Ops', 'destination_hint' => 'Telegram chat 1']);
        $channel->storeSecrets(['bot_token' => '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'chat_id' => '1']);
        $channel->save();

        return $channel;
    }

    public function test_it_answers_404_without_the_monitoring_token(): void
    {
        config(['services.metrics.token' => 'monitor-token-0123456789']);
        $this->postJson('/internal/alertmanager', $this->payload())->assertNotFound();
        $this->postJson('/internal/alertmanager', $this->payload(), ['Authorization' => 'Bearer wrong'])->assertNotFound();
    }

    public function test_an_alert_is_queued_for_administrator_channels_on_the_notifier_lane(): void
    {
        Queue::fake();
        config(['services.metrics.token' => 'monitor-token-0123456789']);
        $channel = $this->channel();

        $this->postJson('/internal/alertmanager', $this->payload(), ['Authorization' => 'Bearer monitor-token-0123456789'])
            ->assertOk()->assertJsonPath('data.queued', 1);
        Queue::assertPushedOn('notifications', DeliverNotification::class, fn (DeliverNotification $job): bool => $job->channelId === $channel->id
            && str_starts_with($job->message['title'], 'Firing: EdgeHeartbeatStale') && $job->message['domain'] === null);
        $this->postJson('/internal/alertmanager', $this->payload('EdgeHeartbeatStale', 'resolved'), ['Authorization' => 'Bearer monitor-token-0123456789'])->assertOk();
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => str_starts_with($job->message['title'], 'Resolved:'));
    }

    public function test_the_watchdog_is_a_heartbeat_and_is_never_sent(): void
    {
        Queue::fake();
        config(['services.metrics.token' => 'monitor-token-0123456789']);
        $this->channel();
        $this->postJson('/internal/alertmanager', $this->payload('Watchdog'), ['Authorization' => 'Bearer monitor-token-0123456789'])
            ->assertOk()->assertJsonPath('data.watchdog', true);
        Queue::assertNothingPushed();
        $this->assertNotNull(Cache::get('notifications:watchdog_received_at'));
    }

    public function test_a_group_of_thousands_of_alerts_is_one_bounded_notification(): void
    {
        Queue::fake();
        config(['services.metrics.token' => 'monitor-token-0123456789']);
        $this->channel();
        $alerts = array_fill(0, 5000, $this->payload('DomainFiveHundredRatioHigh')['alerts'][0]);
        $this->postJson('/internal/alertmanager', ['status' => 'firing', 'alerts' => $alerts], ['Authorization' => 'Bearer monitor-token-0123456789'])->assertOk();
        Queue::assertPushed(DeliverNotification::class, 1);
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => str_contains($job->message['title'], '(5000 alerts)') && substr_count($job->message['body'], "\n") <= 9);
    }
}
