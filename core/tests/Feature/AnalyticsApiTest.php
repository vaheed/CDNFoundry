<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Jobs\BuildUsageRollups;
use App\Models\Domain;
use App\Models\UsageRollup;
use App\Models\User;
use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_analytics_are_scoped_bounded_and_label_partial_data(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();
        Http::fakeSequence()->push(implode("\n", [
            json_encode(['requests' => 10, 'bytes_in' => 120, 'bytes_out' => 900, 'cache_hits' => 8, 'origin_errors' => 1, 'tls_failures' => 0, 'security_blocks' => 2]),
        ]))->push(json_encode(['dns_queries' => 14]));

        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/analytics/summary")->assertForbidden();
        $response = $this->actingAs($user)->getJson("/api/domains/{$domain->id}/analytics/summary")
            ->assertOk()->assertJsonPath('data.requests', 10)->assertJsonPath('data.dns_queries', 14)
            ->assertJsonPath('data.cache_ratio', 0.8)->assertJsonPath('meta.units.bandwidth', 'bytes')
            ->assertJsonPath('meta.partial', true);
        $this->assertNotEmpty($response->json('meta.finalized_until'));
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'domain_id = {domain_id:UInt64}') && str_contains($request->url(), 'param_domain_id='.$domain->id) && str_contains($request->url(), 'max_result_rows=10001'));

        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/analytics/timeseries?from=2025-01-01T00:00:00Z&to=2026-01-01T00:00:00Z")
            ->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_raw_logs_mask_ipv4_and_ipv6_and_use_opaque_cursor_pagination(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $rows = [
            ['occurred_at' => '2026-07-20 10:00:00.000', 'event_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'domain_id' => $domain->id, 'hostname' => $domain->name, 'method' => 'GET', 'path' => '/safe', 'status' => 200, 'client_ip' => '192.0.2.123'],
            ['occurred_at' => '2026-07-20 09:59:00.000', 'event_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'domain_id' => $domain->id, 'hostname' => $domain->name, 'method' => 'GET', 'path' => '/v6', 'status' => 200, 'client_ip' => '2001:db8:1234:5678::1'],
        ];
        Http::fake([config('services.clickhouse.url').'*' => Http::response(collect($rows)->map(fn ($row) => json_encode($row))->implode("\n"))]);

        $response = $this->actingAs($user)->getJson("/api/domains/{$domain->id}/logs/requests")
            ->assertOk()->assertJsonPath('data.0.client_ip', '192.0.2.0/24')
            ->assertJsonPath('data.1.client_ip', '2001:db8:1234::/48')
            ->assertJsonPath('meta.next_cursor', null);
        $this->assertSame('/safe', $response->json('data.0.path'));
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'LIMIT 101') && str_contains($request->body(), 'event_type = \'request\''));
    }

    public function test_clickhouse_failure_is_explicit_and_does_not_touch_serving_state(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $revision = $domain->revision;
        Http::fake([config('services.clickhouse.url').'*' => Http::response('unavailable', 503)]);

        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/analytics/summary")
            ->assertStatus(503)->assertJsonPath('code', 'analytics_unavailable');
        $this->assertSame($revision, $domain->refresh()->revision);
    }

    public function test_admin_global_scope_is_separate_from_domain_scope(): void
    {
        [$user] = $this->ownedDomain();
        $admin = User::factory()->admin()->create();
        Http::fakeSequence()->push(json_encode(['requests' => 3, 'bytes_in' => 0, 'bytes_out' => 20, 'cache_hits' => 1]))->push(json_encode(['dns_queries' => 5]));

        $this->actingAs($user)->getJson('/api/admin/analytics/summary')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/analytics/summary')->assertOk()->assertJsonPath('data.requests', 3);
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'WHERE 1 AND'));
    }

    public function test_usage_rollup_rebuild_is_idempotent_and_exports_stable_json_and_csv(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $from = CarbonImmutable::parse('2026-07-20T08:00:00Z');
        $to = $from->addHour();
        $body = implode("\n", [json_encode(['domain_id' => $domain->id, 'requests' => 7, 'bytes_in' => 70, 'bytes_out' => 700, 'cache_hits' => 5, 'dns_queries' => 0]), json_encode(['domain_id' => $domain->id, 'requests' => 0, 'bytes_in' => 0, 'bytes_out' => 0, 'cache_hits' => 0, 'dns_queries' => 11])]);
        Http::fake([config('services.clickhouse.url').'*' => Http::response($body)]);
        $job = new BuildUsageRollups($from->toIso8601String(), $to->toIso8601String(), $domain->id);
        $job->handle(app(AnalyticsStore::class));
        $job->handle(app(AnalyticsStore::class));

        $this->assertSame(1, UsageRollup::query()->count());
        $this->assertDatabaseHas('usage_rollups', ['domain_id' => $domain->id, 'requests' => 7, 'bytes_out' => 700, 'cache_hits' => 5, 'dns_queries' => 11, 'status' => 'finalized']);
        $query = '?'.http_build_query(['from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to->format('Y-m-d\TH:i:s\Z')]);
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/usage/export{$query}")
            ->assertOk()->assertJsonPath('meta.contract_version', 1)->assertJsonPath('data.0.dns_queries', 11);
        $csv = $this->actingAs($user)->get("/api/domains/{$domain->id}/usage/export{$query}&format=csv")->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('contract_version,domain_id,interval_start', $content);
        $this->assertStringContainsString(',7,70,700,5,11,finalized', $content);
    }

    public function test_admin_rebuild_is_async_bounded_and_coalesced_by_idempotency_key(): void
    {
        Queue::fake();
        [, $domain] = $this->ownedDomain();
        $admin = User::factory()->admin()->create();
        $headers = ['Idempotency-Key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        $payload = ['domain_id' => $domain->id, 'from' => '2026-07-20T08:00:00Z', 'to' => '2026-07-20T10:00:00Z'];
        $first = $this->actingAs($admin)->withHeaders($headers)->postJson('/api/admin/usage/rebuild', $payload)->assertAccepted();
        $this->actingAs($admin)->withHeaders($headers)->postJson('/api/admin/usage/rebuild', $payload)->assertAccepted()
            ->assertJsonPath('data.operation_id', $first->json('data.operation_id'));
        Queue::assertPushed(BuildUsageRollups::class, 1);

        $this->actingAs($admin)->withHeaders(['Idempotency-Key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'])->postJson('/api/admin/usage/rebuild', ['from' => '2026-07-20T08:30:00Z', 'to' => '2026-07-20T10:00:00Z'])
            ->assertUnprocessable();
    }

    public function test_filament_surfaces_show_scope_range_units_partial_state_and_outage(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $admin = User::factory()->admin()->create();
        Http::fake([
            config('services.clickhouse.url').'*' => Http::response(''),
            'http://vector:9598/metrics' => Http::response("vector_buffer_byte_size 42\nvector_component_discarded_events_total 0\n"),
        ]);

        $this->actingAs($user)->get("/app/analytics?domain={$domain->id}")->assertOk()
            ->assertSee($domain->name)->assertSee('Partial / provisional')->assertSee('bytes')->assertSee('milliseconds')
            ->assertSee('Request and bandwidth timeseries')->assertSee('DNS activity')->assertSee('Recent logs')->assertSee('Usage CSV export')
            ->assertDontSee("/api/domains/{$domain->id}/logs", false);
        $this->actingAs($admin)->get('/admin/telemetry')->assertOk()
            ->assertSee('Global traffic')->assertSee('Vector metrics available')->assertSee('Recent logs')->assertSee('Global usage CSV')
            ->assertDontSee('/api/admin/logs', false);

    }

    public function test_filament_surfaces_label_telemetry_outage_without_failing(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $admin = User::factory()->admin()->create();
        Http::fake([config('services.clickhouse.url').'*' => Http::response('down', 503), 'http://vector:9598/metrics' => Http::response('', 503)]);

        $this->actingAs($user)->get("/app/analytics?domain={$domain->id}")->assertOk()->assertSee('Analytics unavailable')->assertSee('serving continue normally');
        $this->actingAs($admin)->get('/admin/telemetry')->assertOk()->assertSee('ClickHouse unavailable')->assertSee('Traffic serving is independent');
    }

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'analytics.example.test', 'display_name' => 'Analytics', 'lifecycle_state' => DomainLifecycleState::Active, 'revision' => 1]);
        $domain->users()->attach($user, ['created_at' => now()]);

        return [$user, $domain];
    }
}
