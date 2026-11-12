<?php

namespace Tests\Feature;

use App\Jobs\PushDomainLogs;
use App\Jobs\TestLogPushDestination;
use App\Models\Domain;
use App\Models\LogPushDestination;
use App\Models\Operation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\LogPush\LogDatasets;
use App\Support\LogPush\LogRowReader;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LogPushTest extends TestCase
{
    use RefreshDatabase;

    private const HTTPS = ['kind' => 'https', 'name' => 'SIEM', 'datasets' => ['http_requests'], 'url' => 'https://93.184.216.34/ingest', 'auth' => 'bearer', 'bearer_token' => 'token-1234567890'];

    private function setting(array $values): void
    {
        $row = SystemSetting::query()->findOrFail('log_push');
        $row->update(['values' => [...$row->values, ...$values]]);
        app()->forgetInstance(PlatformSettings::class);
    }

    /** @return array{0: User, 1: Domain} */
    private function owner(string $name = 'push.example'): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    public function test_adding_a_destination_needs_the_switch_and_never_returns_credentials(): void
    {
        [$user, $domain] = $this->owner();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/log-push", self::HTTPS)->assertForbidden();

        $this->setting(['domain_user_log_push' => true]);
        $response = $this->postJson("/api/domains/{$domain->id}/log-push", self::HTTPS)->assertCreated()->assertJsonPath('data.status', 'active');
        $this->assertStringNotContainsString('token-1234567890', $response->getContent());
        $this->assertStringNotContainsString('93.184.216.34/ingest', $response->getContent());
        $this->assertStringNotContainsString('token-1234567890', LogPushDestination::query()->firstOrFail()->secret_ciphertext);

        $hmac = $this->postJson("/api/domains/{$domain->id}/log-push", [...self::HTTPS, 'name' => 'HMAC', 'auth' => 'hmac'])->assertCreated();
        $this->assertSame(48, strlen($hmac->json('meta.signing_secret')));
        $this->getJson("/api/domains/{$domain->id}/log-push")->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString($hmac->json('meta.signing_secret'), $this->getJson("/api/domains/{$domain->id}/log-push")->getContent());
    }

    public function test_destinations_on_private_loopback_or_platform_addresses_and_plain_http_are_refused(): void
    {
        $this->setting(['domain_user_log_push' => true]);
        [$user, $domain] = $this->owner();
        $this->actingAs($user);
        foreach (['https://127.0.0.1/x', 'https://10.1.2.3/x', 'https://169.254.169.254/x', 'https://[::1]/x', 'http://93.184.216.34/x'] as $url) {
            $this->postJson("/api/domains/{$domain->id}/log-push", [...self::HTTPS, 'url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->postJson("/api/domains/{$domain->id}/log-push", ['kind' => 's3', 'name' => 'Bucket', 'datasets' => ['dns_queries'], 'endpoint' => 'https://127.0.0.1:9000', 'bucket' => 'logs', 'region' => 'us-east-1', 'access_key' => 'AKIAEXAMPLE1', 'secret_key' => 'secretsecret'])
            ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        $this->assertSame(0, LogPushDestination::query()->count());
    }

    public function test_bounds_and_tenancy(): void
    {
        $this->setting(['domain_user_log_push' => true, 'max_destinations_per_domain' => 2, 'max_destinations' => 3]);
        [$alice, $mine] = $this->owner('alice.example');
        [$bob, $theirs] = $this->owner('bob.example');
        $this->actingAs($alice);
        $this->postJson("/api/domains/{$mine->id}/log-push", self::HTTPS)->assertCreated();
        $second = $this->postJson("/api/domains/{$mine->id}/log-push", [...self::HTTPS, 'name' => 'two'])->assertCreated()->json('data.id');
        $this->postJson("/api/domains/{$mine->id}/log-push", [...self::HTTPS, 'name' => 'three'])->assertUnprocessable()->assertJsonValidationErrors('name');
        // Another customer's domain, destination, and test are not reachable.
        $this->getJson("/api/domains/{$theirs->id}/log-push")->assertNotFound();
        $this->postJson("/api/domains/{$theirs->id}/log-push", self::HTTPS)->assertNotFound();
        $this->actingAs($bob)->deleteJson("/api/domains/{$mine->id}/log-push/{$second}")->assertNotFound();
        $this->postJson("/api/domains/{$theirs->id}/log-push", self::HTTPS)->assertCreated();
        $this->postJson("/api/domains/{$theirs->id}/log-push", [...self::HTTPS, 'name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->deleteJson("/api/domains/{$theirs->id}/log-push/{$second}")->assertNotFound();
    }

    public function test_the_dispatcher_queues_only_active_due_destinations_while_the_switch_is_on(): void
    {
        Queue::fake();
        [, $domain] = $this->owner();
        $make = function (string $name, array $attributes = []) use ($domain): LogPushDestination {
            $destination = new LogPushDestination(['domain_id' => $domain->id, 'kind' => 'https', 'name' => $name, 'datasets' => ['http_requests'], 'destination_hint' => 'x', 'status' => 'active', 'cursors' => [], ...$attributes]);
            $destination->storeSecrets(['url' => 'https://93.184.216.34/x', 'auth' => 'bearer', 'bearer_token' => 'token-1234567890']);
            $destination->save();

            return $destination;
        };
        $due = $make('due');
        $make('later', ['next_run_at' => now()->addMinutes(10)]);
        $make('paused', ['status' => 'paused']);

        $this->artisan('cdnf:log-push:dispatch')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->setting(['domain_user_log_push' => true]);
        $this->artisan('cdnf:log-push:dispatch')->assertSuccessful();
        Queue::assertPushedOn('log-push', PushDomainLogs::class);
        Queue::assertPushed(PushDomainLogs::class, 1);
        Queue::assertPushed(PushDomainLogs::class, fn (PushDomainLogs $job): bool => $job->destinationId === $due->id);
    }

    private function destination(Domain $domain, array $attributes = []): LogPushDestination
    {
        $destination = new LogPushDestination(['domain_id' => $domain->id, 'kind' => 'https', 'name' => 'SIEM', 'datasets' => ['http_requests'], 'destination_hint' => 'x', 'status' => 'active', 'cursors' => [],
            'consecutive_failures' => 0, 'failures_total' => 0, 'rows_pushed' => 0, 'bytes_pushed' => 0, ...$attributes]);
        $destination->storeSecrets(['url' => 'https://93.184.216.34/ingest', 'auth' => 'hmac', 'signing_secret' => 'signing-secret']);
        $destination->save();

        return $destination;
    }

    private function fakeStore(array $rowsByCall): LogRowReader
    {
        return new class($rowsByCall) extends LogRowReader
        {
            public array $calls = [];

            public function __construct(private array $rowsByCall) {}

            public function after(string $dataset, int $domainId, string $afterAt, string $afterId, \Carbon\CarbonImmutable $until, int $limit): array
            {
                $this->calls[] = compact('dataset', 'domainId', 'afterAt', 'afterId');

                return array_shift($this->rowsByCall) ?? [];
            }
        };
    }

    public function test_a_push_sends_gzip_ndjson_with_only_allowlisted_fields_and_advances_the_cursor(): void
    {
        $this->setting(['domain_user_log_push' => true]);
        Http::fake(['*' => Http::response('', 200)]);
        [, $domain] = $this->owner();
        $destination = $this->destination($domain, ['truncate_client_ip' => true]);
        $row = ['occurred_at' => '2026-11-12 10:00:00.123', 'event_id' => '11111111-1111-4111-8111-111111111111', 'edge_id' => 'edge-a', 'cell' => 'cell-01', 'origin_error' => '10.0.0.5 refused',
            ...array_fill_keys(LogDatasets::fields('http_requests'), 'v'), 'client_ip' => '203.0.113.77', 'status' => 200];
        $store = $this->fakeStore([[$row], []]);

        (new PushDomainLogs($destination->id))->handle($store, app(\App\Support\LogPush\LogPushSender::class), app(PlatformSettings::class));

        Http::assertSent(function ($request) {
            $body = gzdecode($request->body());
            $line = json_decode(trim($body), true);

            return $request->url() === 'https://93.184.216.34/ingest' && $request->header('Content-Encoding')[0] === 'gzip'
                && str_starts_with($request->header('X-CDNFoundry-Signature')[0], 'sha256=') && $request->header('X-CDNFoundry-Dataset')[0] === 'http_requests'
                && $line['client_ip'] === '203.0.113.0' && ! array_key_exists('edge_id', $line) && ! array_key_exists('cell', $line) && ! array_key_exists('origin_error', $line)
                && array_keys($line) === ['dataset', 'event_id', 'occurred_at', ...LogDatasets::fields('http_requests')];
        });
        $destination->refresh();
        $this->assertSame(['at' => '2026-11-12 10:00:00.123', 'id' => '11111111-1111-4111-8111-111111111111'], $destination->cursors['http_requests']);
        $this->assertSame(1, $destination->rows_pushed);
        $this->assertGreaterThan(0, $destination->bytes_pushed);
        $this->assertNotNull($destination->last_delivery_at);
        $this->assertSame($domain->id, $store->calls[0]['domainId'], 'rows are read for the destination\'s own domain only');
    }

    public function test_a_failing_destination_keeps_its_cursor_backs_off_and_notifies_once(): void
    {
        $this->setting(['domain_user_log_push' => true, 'failure_notify_after' => 2]);
        Queue::fake();
        Http::fake(['*' => Http::response('no', 500)]);
        [, $domain] = $this->owner();
        $cursor = ['at' => '2026-11-12 09:00:00.000', 'id' => '00000000-0000-0000-0000-000000000000'];
        $destination = $this->destination($domain, ['cursors' => ['http_requests' => $cursor]]);
        $row = ['occurred_at' => '2026-11-12 10:00:00.123', 'event_id' => '11111111-1111-4111-8111-111111111111', ...array_fill_keys(LogDatasets::fields('http_requests'), 'v')];
        $channel = new \App\Models\NotificationChannel(['type' => 'telegram', 'name' => 'ops', 'destination_hint' => 'x', 'status' => 'active']);
        $channel->storeSecrets(['bot_token' => 'x', 'chat_id' => '1']);
        $channel->save();

        for ($run = 0; $run < 3; $run++) {
            (new PushDomainLogs($destination->id))->handle($this->fakeStore([[$row]]), app(\App\Support\LogPush\LogPushSender::class), app(PlatformSettings::class));
        }
        $destination->refresh();
        $this->assertSame($cursor, $destination->cursors['http_requests'], 'the cursor stays where the last accepted batch left it');
        $this->assertSame(3, $destination->consecutive_failures);
        $this->assertStringContainsString('HTTP 500', $destination->last_error);
        $this->assertTrue($destination->next_run_at->isFuture());
        Queue::assertPushed(\App\Jobs\DeliverNotification::class, 1);
    }

    public function test_a_destination_behind_the_retention_window_skips_ahead_and_records_the_gap(): void
    {
        $this->setting(['domain_user_log_push' => true]);
        Http::fake();
        [, $domain] = $this->owner();
        $destination = $this->destination($domain, ['cursors' => ['http_requests' => ['at' => now()->subDays(30)->format('Y-m-d H:i:s.v'), 'id' => '00000000-0000-0000-0000-000000000000']]]);
        $store = $this->fakeStore([[]]);
        (new PushDomainLogs($destination->id))->handle($store, app(\App\Support\LogPush\LogPushSender::class), app(PlatformSettings::class));
        $destination->refresh();
        $this->assertNotNull($destination->last_gap_at);
        $this->assertTrue(\Carbon\CarbonImmutable::parse($store->calls[0]['afterAt'], 'UTC')->gt(now()->subDays(8)), 'reading resumes inside the retention window');
    }

    public function test_the_switch_off_stops_a_queued_push_and_keeps_the_destination(): void
    {
        Http::fake();
        [, $domain] = $this->owner();
        $destination = $this->destination($domain);
        $store = $this->fakeStore([[['occurred_at' => '2026-11-12 10:00:00.123', 'event_id' => '11111111-1111-4111-8111-111111111111']]]);
        (new PushDomainLogs($destination->id))->handle($store, app(\App\Support\LogPush\LogPushSender::class), app(PlatformSettings::class));
        Http::assertNothingSent();
        $this->assertSame([], $store->calls);
        $this->assertDatabaseHas('log_push_destinations', ['id' => $destination->id]);
    }

    public function test_a_test_is_an_operation_and_s3_destinations_use_signed_put(): void
    {
        $this->setting(['domain_user_log_push' => true]);
        Queue::fake();
        [$user, $domain] = $this->owner();
        $id = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/log-push", ['kind' => 's3', 'name' => 'Bucket', 'datasets' => ['dns_queries', 'security_events'], 'endpoint' => 'https://93.184.216.34:9000',
            'bucket' => 'cdn-logs', 'region' => 'us-east-1', 'prefix' => 'cdnf', 'access_key' => 'AKIAEXAMPLE1', 'secret_key' => 'secretsecret'])->assertCreated()->json('data.id');
        $operationId = $this->postJson("/api/domains/{$domain->id}/log-push/{$id}/test")->assertStatus(202)->json('data.operation_id');
        Queue::assertPushedOn('log-push', TestLogPushDestination::class);
        $this->getJson("/api/operations/{$operationId}")->assertOk()->assertJsonPath('data.status', 'pending');

        Http::fake(['*' => Http::response('', 200)]);
        (new TestLogPushDestination($operationId))->handle(app(\App\Support\LogPush\LogPushSender::class));
        $this->assertSame('succeeded', Operation::query()->findOrFail($operationId)->status);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && str_starts_with($request->url(), 'https://93.184.216.34:9000/cdn-logs/cdnf/'.$domain->id.'/test/')
            && str_contains($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE1/') && str_contains($request->header('Authorization')[0], 'Signature='));
    }

    public function test_pause_resume_and_delete_are_audited(): void
    {
        $this->setting(['domain_user_log_push' => true]);
        [$user, $domain] = $this->owner();
        $id = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/log-push", self::HTTPS)->json('data.id');
        $this->patchJson("/api/domains/{$domain->id}/log-push/{$id}", ['status' => 'paused'])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->patchJson("/api/domains/{$domain->id}/log-push/{$id}", ['status' => 'active'])->assertOk();
        $this->deleteJson("/api/domains/{$domain->id}/log-push/{$id}")->assertOk();
        $this->assertSame(4, \App\Models\AuditLog::query()->where('action', 'like', 'log_push.%')->count());
    }
}
