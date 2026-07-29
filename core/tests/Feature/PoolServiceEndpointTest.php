<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\PlatformDnsZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PoolServiceEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_ipv4_ipv6_or_dual_stack_endpoints_and_conflicts_fail_before_activation(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $first = $this->edge('endpoint-first', '203.0.113.10');
        $second = $this->edge('endpoint-second', '203.0.113.11');

        $url = "/api/admin/edge-pools/{$pool->id}/edges/{$first->id}/endpoint";
        $this->actingAs($user)->postJson($url, ['ipv4' => '8.8.4.4'])->assertForbidden();
        $created = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, ['ipv4' => '8.8.4.4', 'ipv6' => '2606:4700:4700::1111'])->assertAccepted();
        $this->assertSame('pending', $created->json('data.gateway_state'));

        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/edge-pools/{$pool->id}/edges/{$second->id}/endpoint", ['ipv4' => '8.8.4.4'])
            ->assertUnprocessable()->assertJsonValidationErrors('ipv4');

        $reserved = EdgePool::query()->create(['name' => 'reserved-endpoint', 'kind' => 'reserved', 'enabled' => true]);
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/edge-pools/{$reserved->id}/edges/{$first->id}/endpoint", ['ipv6' => '2606:4700:4700::1001'])
            ->assertAccepted()->assertJsonPath('data.ipv4', null);
    }

    public function test_ready_endpoint_owns_one_pair_for_three_cells_and_dns_withdrawal_is_isolated(): void
    {
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $pool->update(['minimum_ready_cells' => 3]);
        $reserved = EdgePool::query()->create(['name' => 'reserved-pair', 'kind' => 'reserved', 'enabled' => true]);
        $edge = $this->edge('endpoint-routing', '203.0.113.20');
        foreach ([1, 2, 3] as $slot) {
            $edge->cells()->create(['slot' => $slot, 'edge_pool_id' => $pool->id, 'status' => 'ready']);
        }
        $edge->cells()->create(['slot' => 4, 'edge_pool_id' => $reserved->id, 'status' => 'ready']);
        $sharedEndpoint = $pool->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => '8.8.8.8', 'ipv6' => '2606:4700:4700::1111', 'revision' => 4, 'gateway_revision' => 4, 'gateway_state' => 'ready']);
        $reserved->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => '1.1.1.1', 'revision' => 2, 'gateway_revision' => 2, 'gateway_state' => 'ready']);
        $settings = $this->settings();

        $addresses = $this->addresses(PlatformDnsZone::render($settings));
        $this->assertContains('8.8.8.8', $addresses);
        $this->assertContains('2606:4700:4700::1111', $addresses);
        $this->assertContains('1.1.1.1', $addresses);

        $sharedEndpoint->update(['withdrawn' => true]);
        $addresses = $this->addresses(PlatformDnsZone::render($settings));
        $this->assertNotContains('8.8.8.8', $addresses);
        $this->assertContains('1.1.1.1', $addresses);
    }

    public function test_gateway_candidate_contains_endpoint_pair_and_all_participating_cells(): void
    {
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $edge = $this->edge('gateway-candidate', '203.0.113.30');
        foreach ([1, 2, 3] as $slot) {
            $edge->cells()->create(['slot' => $slot, 'edge_pool_id' => $pool->id, 'status' => $slot === 3 ? 'degraded' : 'ready']);
        }
        $endpoint = $pool->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => '8.8.8.8', 'ipv6' => '2606:4700:4700::1111', 'revision' => 7]);
        $settings = $this->settings();
        $settings->update(['revision' => 99]);
        $request = Request::create('/edge/v1/gateway/config');
        $request->attributes->set('edge', $edge);
        $payload = app(EdgeAgentController::class)->gatewayConfig($request)->getData(true)['data'];

        $this->assertSame(7, $payload['revision']);
        $this->assertSame(['2606:4700:4700::1111', '8.8.8.8'], collect($payload['bindings'])->pluck('address')->sort()->values()->all());
        $this->assertSame(['cell-01', 'cell-02', 'cell-03'], collect($payload['bindings'][0]['cells'])->pluck('name')->all());

        $endpoint->update(['gateway_revision' => 88]);
        $payload = app(EdgeAgentController::class)->gatewayConfig($request)->getData(true)['data'];
        $this->assertSame(88, $payload['revision']);
    }

    public function test_endpoint_address_families_can_be_removed_and_withdrawn_endpoint_can_be_deleted(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $edge = $this->edge('removable-endpoint', '10.20.30.40');
        $endpoint = $pool->endpoints()->create([
            'edge_id' => $edge->id,
            'ipv4' => '8.8.4.4',
            'ipv6' => '2606:4700:4700::1111',
        ]);
        $url = "/api/admin/edge-pools/{$pool->id}/endpoints/{$endpoint->id}";

        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson($url, ['ipv6' => null])->assertAccepted()
            ->assertJsonPath('data.ipv4', '8.8.4.4')->assertJsonPath('data.ipv6', null);
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson($url, ['ipv4' => null])->assertUnprocessable()->assertJsonValidationErrors('ipv4');
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->deleteJson($url)->assertConflict();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson($url, ['withdrawn' => true])->assertAccepted();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->deleteJson($url)->assertNoContent();
        $this->assertDatabaseMissing('edge_pool_endpoints', ['id' => $endpoint->id]);
    }

    public function test_edge_management_addresses_are_optional_private_and_distinct_from_service_endpoints(): void
    {
        $admin = User::factory()->admin()->create();
        $withoutManagement = $this->actingAs($admin)->postJson('/api/admin/edges', [
            'name' => 'no-management-address', 'country_code' => 'IR', 'continent_code' => 'AS',
        ])->assertCreated();
        $privateManagement = $this->actingAs($admin)->postJson('/api/admin/edges', [
            'name' => 'private-management-address', 'country_code' => 'DE', 'continent_code' => 'EU',
            'management_ipv4' => '10.20.30.40',
        ])->assertCreated();
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();

        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/edge-pools/{$pool->id}/edges/{$privateManagement->json('data.id')}/endpoint", ['ipv4' => '10.20.30.40'])
            ->assertUnprocessable()->assertJsonValidationErrors('ipv4');
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/edges', [
            'name' => 'legacy-edge-address', 'country_code' => 'IR', 'continent_code' => 'AS', 'ipv4' => '8.8.8.8',
        ])->assertUnprocessable()->assertJsonValidationErrors('ipv4');
        $this->assertNull($withoutManagement->json('data.management_ipv4'));
    }

    private function edge(string $name, string $management): Edge
    {
        return Edge::query()->create(['name' => $name, 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => $management,
            'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now(), 'capacity' => ['listener_ready' => true]]);
    }

    private function settings(): PlatformDnsSetting
    {
        return PlatformDnsSetting::query()->create(['id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test', 'ipv4' => '192.0.2.10']], 'soa_primary' => 'ns1.cdnf.test',
            'soa_mailbox' => 'hostmaster.cdnf.test', 'soa_refresh' => 3600, 'soa_retry' => 600, 'soa_expire' => 1209600,
            'soa_minimum_ttl' => 60, 'default_ttl' => 60, 'cluster_targets' => ['pdns-auth:8081'], 'revision' => 1]);
    }

    private function addresses(array $rows): array
    {
        return collect($rows)->whereIn('type', ['A', 'AAAA'])->flatMap(fn (array $row): array => $row['records'])->pluck('content')->all();
    }
}
