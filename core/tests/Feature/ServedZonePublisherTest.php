<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Support\ServedZonePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServedZonePublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.clickhouse.url' => 'http://clickhouse.test:8123', 'services.clickhouse.zones_password' => 'zones-secret']);
        Cache::flush();
        $this->clickHouseAnswers(200);
    }

    private function clickHouseAnswers(int $status): void
    {
        Http::swap(new Factory);
        Http::fake(['clickhouse.test:*' => Http::response($status === 200 ? '' : 'Code: 497. not enough privileges', $status)]);
    }

    public function test_it_publishes_a_snapshot_of_active_and_pending_zones_signed_in_as_the_publisher_account(): void
    {
        $active = Domain::query()->create(['name' => 'Active.Example', 'display_name' => 'a', 'revision' => 1, 'lifecycle_state' => 'active']);
        $pending = Domain::query()->create(['name' => 'pending.example', 'display_name' => 'p', 'revision' => 1, 'lifecycle_state' => 'pending_verification']);
        Domain::query()->create(['name' => 'gone.example', 'display_name' => 'g', 'revision' => 1, 'lifecycle_state' => 'deprovisioning']);

        $this->assertSame(2, app(ServedZonePublisher::class)->publish());

        Http::assertSent(function (Request $request) use ($active, $pending): bool {
            $rows = array_map(fn (string $line): array => json_decode($line, true), explode("\n", $request->body()));
            $generations = array_unique(array_column($rows, 'generation'));

            return str_contains($request->url(), 'INSERT+INTO+served_zones') && $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic '.base64_encode('cdnf_zones:zones-secret'))
                && count($generations) === 1
                && collect($rows)->pluck('domain_id', 'zone')->all() === ['active.example' => $active->id, 'pending.example' => $pending->id];
        });
    }

    public function test_a_name_claimed_twice_is_published_once_for_the_active_holder(): void
    {
        Domain::query()->create(['name' => 'shared.example', 'display_name' => 'old claim', 'revision' => 1, 'lifecycle_state' => 'pending_verification']);
        $holder = Domain::query()->create(['name' => 'shared.example', 'display_name' => 'holder', 'revision' => 1, 'lifecycle_state' => 'active']);

        $this->assertSame(1, app(ServedZonePublisher::class)->publish());

        Http::assertSent(fn (Request $request): bool => collect(explode("\n", $request->body()))->map(fn (string $line): array => json_decode($line, true))
            ->pluck('domain_id', 'zone')->all() === ['shared.example' => $holder->id]);
    }

    public function test_an_unchanged_list_is_not_published_again_until_it_is_forced(): void
    {
        Domain::query()->create(['name' => 'stable.example', 'display_name' => 's', 'revision' => 1, 'lifecycle_state' => 'active']);
        $publisher = app(ServedZonePublisher::class);

        $this->assertSame(1, $publisher->publish());
        $this->assertSame(0, $publisher->publish());
        $this->assertSame(1, $publisher->publish(force: true));
        Domain::query()->create(['name' => 'second.example', 'display_name' => 's', 'revision' => 1, 'lifecycle_state' => 'active']);
        $this->assertSame(2, $publisher->publish());
        Http::assertSentCount(3);
    }

    public function test_nothing_is_sent_when_no_publisher_credential_is_configured(): void
    {
        config(['services.clickhouse.zones_password' => '']);
        Domain::query()->create(['name' => 'quiet.example', 'display_name' => 'q', 'revision' => 1, 'lifecycle_state' => 'active']);

        $this->assertSame(0, app(ServedZonePublisher::class)->publish());
        Http::assertNothingSent();
    }

    public function test_a_refused_snapshot_is_an_error_and_is_retried_on_the_next_run(): void
    {
        $this->clickHouseAnswers(403);
        Domain::query()->create(['name' => 'retry.example', 'display_name' => 'r', 'revision' => 1, 'lifecycle_state' => 'active']);

        try {
            app(ServedZonePublisher::class)->publish();
            $this->fail('A refused snapshot must raise.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ClickHouse refused the served-zone snapshot', $exception->getMessage());
        }

        $this->clickHouseAnswers(200);
        $this->assertSame(1, app(ServedZonePublisher::class)->publish(), 'the failed snapshot must not be remembered as published');
    }
}
