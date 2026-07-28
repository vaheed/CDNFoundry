<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Jobs\ProvisionEdgePoolCells;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\PlatformDnsZone;
use App\Support\PowerDnsZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SimpleAnycastPoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_bounded_pool_pair_and_attaches_explicit_edges(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $payload = [
            'name' => 'shared-anycast', 'kind' => 'shared', 'routing_mode' => 'simple_anycast',
            'anycast_ipv4' => '8.8.8.8', 'anycast_ipv6' => '2606:4700:4700::1111',
        ];

        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/edge-pools', $payload)->assertForbidden();
        $response = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/edge-pools', $payload)->assertCreated();
        $pool = EdgePool::query()->findOrFail($response->json('data.id'));
        $edge = $this->edge('anycast-pop-a', 'IR', 'AS');
        $cell = $edge->cells()->create(['slot' => 1, 'status' => 'unassigned']);
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->putJson("/api/admin/edge-pools/{$pool->id}/cells/{$cell->id}", [])
            ->assertAccepted();
        $endpoint = $pool->endpoints()->where('edge_id', $edge->id)->firstOrFail();
        $this->assertNull($endpoint->ipv4);
        $this->assertNull($endpoint->ipv6);
        $this->assertSame('8.8.8.8', $endpoint->effectiveAddress('ipv4'));
        $this->assertSame('2606:4700:4700::1111', $endpoint->effectiveAddress('ipv6'));

        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/edge-pools/{$pool->id}/edges/{$edge->id}/endpoint", [])
            ->assertConflict();
        $second = $this->edge('anycast-pop-b', 'DE', 'EU');
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/edge-pools/{$pool->id}/edges/{$second->id}/endpoint", ['ipv4' => '1.1.1.1'])
            ->assertConflict();
    }

    public function test_disabled_empty_pool_can_be_deleted_after_last_anycast_cell_is_unassigned(): void
    {
        $admin = User::factory()->admin()->create();
        $pool = EdgePool::query()->create([
            'name' => 'temporary-anycast', 'kind' => 'shared', 'routing_mode' => 'simple_anycast',
            'anycast_ipv4' => '8.8.4.4', 'enabled' => false,
        ]);
        $edge = $this->edge('temporary-pop', 'IR', 'AS');
        $cell = $edge->cells()->create(['slot' => 1, 'status' => 'unassigned']);
        $this->actingAs($admin)->putJson("/api/admin/edge-pools/{$pool->id}/cells/{$cell->id}", [])->assertAccepted();

        $this->actingAs($admin)->deleteJson("/api/admin/edge-pools/{$pool->id}")->assertConflict();
        $this->actingAs($admin)->deleteJson("/api/admin/edge-pools/{$pool->id}/cells/{$cell->id}")->assertNoContent();

        $this->assertFalse($pool->endpoints()->exists());
        $this->actingAs($admin)->deleteJson("/api/admin/edge-pools/{$pool->id}")->assertNoContent();
        $this->assertDatabaseMissing('edge_pools', ['id' => $pool->id]);
    }

    public function test_legacy_provisioning_job_fails_closed_without_assigning_a_cell(): void
    {
        $admin = User::factory()->admin()->create();
        $pool = EdgePool::query()->create(['name' => 'explicit-only', 'kind' => 'shared']);
        $edge = $this->edge('legacy-job-pop', 'IR', 'AS');
        $cell = $edge->cells()->create(['slot' => 1, 'status' => 'unassigned']);
        $operation = Operation::query()->create([
            'actor_id' => $admin->id,
            'type' => 'edge.pool_provision',
            'status' => 'pending',
            'input' => ['pool_id' => $pool->id],
        ]);

        (new ProvisionEdgePoolCells($pool->id, $operation->id))->handle();

        $this->assertNull($cell->refresh()->edge_pool_id);
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame('automatic_cell_assignment_removed', $operation->error);
    }

    public function test_two_pops_bind_one_pair_and_geo_unicast_coexists(): void
    {
        $anycast = EdgePool::query()->create([
            'name' => 'multi-pop', 'kind' => 'shared', 'routing_mode' => 'simple_anycast', 'enabled' => true,
            'anycast_ipv4' => '8.8.8.8', 'anycast_ipv6' => '2606:4700:4700::1111',
        ]);
        $geo = EdgePool::query()->create(['name' => 'geo-reserved', 'kind' => 'reserved', 'routing_mode' => 'geo_unicast', 'enabled' => true]);
        $first = $this->readyEndpoint($anycast, $this->edge('pop-a', 'IR', 'AS'));
        $second = $this->readyEndpoint($anycast, $this->edge('pop-b', 'DE', 'EU'));
        $geoEdge = $this->edge('geo-pop', 'US', 'NA');
        $geoEdge->cells()->create(['slot' => 1, 'edge_pool_id' => $geo->id, 'status' => 'ready']);
        $geo->endpoints()->create(['edge_id' => $geoEdge->id, 'ipv4' => '1.1.1.1', 'revision' => 1, 'gateway_revision' => 1, 'gateway_state' => 'ready']);

        foreach ([$first->edge, $second->edge] as $edge) {
            $request = Request::create('/edge/v1/gateway/config');
            $request->attributes->set('edge', $edge);
            $bindings = app(EdgeAgentController::class)->gatewayConfig($request)->getData(true)['data']['bindings'];
            $this->assertSame(['2606:4700:4700::1111', '8.8.8.8'], collect($bindings)->pluck('address')->sort()->values()->all());
        }

        $rows = collect(PlatformDnsZone::render($this->settings()));
        $poolRows = $rows->where('name', 'pool-'.$anycast->id.'.proxy.cdnf.test.');
        $this->assertSame(['A', 'AAAA'], $poolRows->pluck('type')->sort()->values()->all());
        $this->assertSame([['content' => '8.8.8.8', 'disabled' => false]], $poolRows->where('type', 'A')->first()['records']);
        $this->assertTrue($rows->flatMap(fn (array $row): array => $row['records'])->pluck('content')->contains('1.1.1.1'));
    }

    public function test_pop_failure_degrades_pool_without_changing_other_pop_candidate_and_withdrawal_is_explicit(): void
    {
        $pool = EdgePool::query()->create([
            'name' => 'failure-isolation', 'kind' => 'shared', 'routing_mode' => 'simple_anycast', 'enabled' => true,
            'anycast_ipv4' => '8.8.4.4',
        ]);
        $first = $this->readyEndpoint($pool, $this->edge('healthy-pop', 'IR', 'AS'));
        $second = $this->readyEndpoint($pool, $this->edge('failed-pop', 'DE', 'EU'));
        $this->assertSame('ready', $pool->routingStatus());

        $second->edge->update(['drained' => true]);
        $this->assertSame('degraded', $pool->routingStatus());
        $request = Request::create('/edge/v1/gateway/config');
        $request->attributes->set('edge', $first->edge);
        $this->assertSame('8.8.4.4', app(EdgeAgentController::class)->gatewayConfig($request)->getData(true)['data']['bindings'][0]['address']);

        $first->edge->update(['drained' => true]);
        $this->assertSame('unavailable', $pool->routingStatus());

        $pool->update(['withdrawn' => true]);
        $this->assertSame('withdrawn', $pool->routingStatus());
        $this->assertFalse(collect(PlatformDnsZone::render($this->settings()))->flatMap(fn (array $row): array => $row['records'])->pluck('content')->contains('8.8.4.4'));
    }

    public function test_anycast_validation_rejects_missing_unsafe_and_conflicting_pairs(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $base = ['name' => 'invalid-anycast', 'kind' => 'shared', 'routing_mode' => 'simple_anycast'];
        $post = fn (array $payload) => $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/edge-pools', $payload);

        $post($base)->assertUnprocessable()->assertJsonValidationErrors('anycast_ipv4');
        $post([...$base, 'anycast_ipv4' => '127.0.0.1'])->assertUnprocessable()->assertJsonValidationErrors('anycast_ipv4');
        $geo = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $geo->endpoints()->create(['edge_id' => $this->edge('conflict-pop', 'IR', 'AS')->id, 'ipv4' => '8.8.8.8']);
        $post([...$base, 'anycast_ipv4' => '8.8.8.8'])->assertUnprocessable()->assertJsonValidationErrors('anycast_ipv4');
    }

    public function test_gateway_acknowledgement_transition_queues_fresh_dns_reconciliation(): void
    {
        Queue::fake();
        $this->settings();
        $pool = EdgePool::query()->create([
            'name' => 'acknowledgement-race', 'kind' => 'shared', 'routing_mode' => 'simple_anycast', 'enabled' => true,
            'anycast_ipv4' => '8.8.8.8',
        ]);
        $edge = $this->edge('acknowledgement-pop', 'IR', 'AS');
        $edge->cells()->create(['slot' => 1, 'edge_pool_id' => $pool->id, 'status' => 'ready']);
        $endpoint = $pool->endpoints()->create(['edge_id' => $edge->id, 'revision' => 2, 'gateway_state' => 'pending']);
        $request = Request::create('/edge/v1/heartbeat', 'POST', [
            'agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => 0,
            'cells' => [['name' => 'cell-01', 'status' => 'ready', 'capacity' => ['active_connections' => 0]]],
            'gateway' => ['ready' => true, 'active_revision' => 2, 'routes' => 1, 'listeners' => 2],
        ]);
        $request->attributes->set('edge', $edge);

        $this->assertSame(200, app(EdgeAgentController::class)->heartbeat($request)->getStatusCode());

        $this->assertSame('ready', $endpoint->refresh()->gateway_state);
        Queue::assertPushed(ReconcilePlatformDnsIdentity::class);
    }

    public function test_heartbeat_accepts_durable_acknowledged_sequence_after_artifact_retention(): void
    {
        Queue::fake();
        $edge = $this->edge('retained-sequence-pop', 'IR', 'AS');
        $edge->update(['active_sequence' => 42]);
        $edge->cells()->create(['slot' => 1, 'status' => 'unassigned']);
        $payload = [
            'agent_version' => '1.2.0', 'listener_ready' => false, 'active_sequence' => 42,
            'cells' => [['name' => 'cell-01', 'status' => 'stopped', 'capacity' => ['active_connections' => 0]]],
        ];
        $request = Request::create('/edge/v1/heartbeat', 'POST', $payload);
        $request->attributes->set('edge', $edge);

        $this->assertSame(200, app(EdgeAgentController::class)->heartbeat($request)->getStatusCode());
        $this->assertNotNull($edge->refresh()->last_heartbeat_at);

        $future = Request::create('/edge/v1/heartbeat', 'POST', [...$payload, 'active_sequence' => 43]);
        $future->attributes->set('edge', $edge);
        $this->expectException(ValidationException::class);
        app(EdgeAgentController::class)->heartbeat($future);
    }

    public function test_anycast_apex_follows_readiness_gated_pool_hostname(): void
    {
        $settings = $this->settings();
        $pool = EdgePool::query()->create([
            'name' => 'apex-withdrawal', 'kind' => 'shared', 'routing_mode' => 'simple_anycast', 'enabled' => true,
            'anycast_ipv4' => '8.8.8.8',
        ]);
        $domain = Domain::query()->create(['name' => 'anycast-apex.test', 'display_name' => 'Anycast apex', 'lifecycle_state' => 'active', 'revision' => 1]);
        $domain->edgePlacement()->create(['active_pool_id' => $pool->id, 'state' => 'active', 'desired_revision' => $domain->revision]);
        $domain->dnsRecords()->create(['name' => $domain->name, 'type' => 'A', 'content' => '', 'content_hash' => hash('sha256', ''), 'mode' => 'proxied', 'ttl' => 60, 'origin' => [
            'host' => '1.1.1.1', 'port' => 80, 'scheme' => 'http', 'host_header' => $domain->name,
        ]]);

        $content = collect(PowerDnsZone::render($domain))->firstWhere('type', 'LUA')['records'][0]['content'];

        $this->assertStringContainsString("dblookup('pool-{$pool->id}.{$settings->proxy_hostname}.'", $content);
        $this->assertStringNotContainsString('8.8.8.8', $content);
    }

    private function readyEndpoint(EdgePool $pool, Edge $edge)
    {
        $edge->cells()->create(['slot' => 1, 'edge_pool_id' => $pool->id, 'status' => 'ready']);

        return $pool->endpoints()->create(['edge_id' => $edge->id, 'revision' => 1, 'gateway_revision' => 1, 'gateway_state' => 'ready'])->load(['edge', 'pool']);
    }

    private function edge(string $name, string $country, string $continent): Edge
    {
        return Edge::query()->create([
            'name' => $name, 'country_code' => $country, 'continent_code' => $continent, 'enabled' => true,
            'registered_at' => now(), 'last_heartbeat_at' => now(), 'active_sequence' => 0, 'capacity' => ['listener_ready' => true],
        ]);
    }

    private function settings(): PlatformDnsSetting
    {
        return PlatformDnsSetting::query()->firstOrCreate(['id' => 1], [
            'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test', 'ipv4' => '192.0.2.10']], 'soa_primary' => 'ns1.cdnf.test',
            'soa_mailbox' => 'hostmaster.cdnf.test', 'soa_refresh' => 3600, 'soa_retry' => 600, 'soa_expire' => 1209600,
            'soa_minimum_ttl' => 60, 'default_ttl' => 60, 'cluster_targets' => ['pdns-auth:8081'], 'revision' => 1,
        ]);
    }
}
