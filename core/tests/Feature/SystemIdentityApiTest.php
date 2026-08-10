<?php

namespace Tests\Feature;

use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\DnsCluster;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\Operation;
use App\Models\PlatformDnsDeployment;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\PlatformDnsZone;
use App\Support\PowerDnsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SystemIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_validates_and_applies_typed_dns_identity_through_an_operation(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $payload = $this->validPayload();
        DnsCluster::query()->create([
            'name' => 'dns-local', 'location' => 'test', 'enabled' => true, 'last_health_status' => 'healthy',
            'api_url' => 'http://pdns-auth:8081', 'api_key' => 'private-test-key', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']],
        ]);
        Http::fake(fn (Request $request) => match ($request->method()) {
            'GET' => Http::response([], 404),
            'POST' => Http::response([], 201),
            'PATCH' => Http::response([], 204),
        });

        $validation = $this->actingAs($admin)->postJson('/api/admin/system/settings/dns/validate', $payload)
            ->assertOk()->assertJsonPath('data.valid', true);

        $response = $this->actingAs($admin)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/dns', [...$payload, 'confirmation_token' => $validation->json('data.confirmation_token')])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $operationId = $response->json('data.id');
        Queue::assertPushed(
            ApplyPlatformDnsSettings::class,
            fn (ApplyPlatformDnsSettings $job): bool => $job->operationId === $operationId,
        );
        (new ApplyPlatformDnsSettings($operationId))->handle(app(PowerDnsClient::class));
        $this->assertDatabaseHas('operations', ['id' => $operationId, 'type' => 'platform_dns_identity.update', 'status' => 'succeeded']);
        $this->assertDatabaseHas('platform_dns_settings', ['id' => 1, 'platform_domain' => 'cdnf.test']);
        $this->actingAs($admin)->getJson("/api/operations/$operationId")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_dns_settings.update_requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_dns_settings.applied']);
    }

    public function test_platform_identity_deploys_soa_ns_and_nameserver_glue_to_healthy_clusters(): void
    {
        $admin = User::factory()->admin()->create();
        $cluster = DnsCluster::query()->create([
            'name' => 'dns-local', 'location' => 'test', 'enabled' => true, 'last_health_status' => 'healthy',
            'api_url' => 'http://pdns-auth:8081', 'api_key' => 'private-test-key', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']],
        ]);
        $operation = Operation::query()->create([
            'actor_id' => $admin->id, 'type' => 'platform_dns_identity.update', 'status' => 'pending',
            'input' => ['settings_id' => 1, 'revision' => 1],
        ]);
        PlatformDnsSetting::query()->create(['id' => 1, ...$this->validPayload(), 'revision' => 1]);
        Http::fake(fn (Request $request) => match ($request->method()) {
            'GET' => Http::response([], 404),
            'POST' => Http::response([], 201),
            'PATCH' => Http::response([], 204),
        });

        (new ApplyPlatformDnsSettings($operation->id))->handle(app(PowerDnsClient::class));

        $deployment = PlatformDnsDeployment::query()->where('dns_cluster_id', $cluster->id)->firstOrFail();
        $this->assertSame('succeeded', $deployment->status);
        $this->assertSame('cdnf.test', $deployment->active_zone);
        $this->assertSame(1, $deployment->deployed_revision);
        $this->assertSame(['A', 'AAAA', 'NS', 'SOA'], collect($deployment->active_rrsets)->pluck('type')->unique()->sort()->values()->all());
        $this->assertSame('succeeded', $operation->refresh()->status);
        $this->assertSame(1, $operation->result['targets']);
    }

    public function test_platform_identity_fails_when_no_healthy_cluster_matches_the_configured_targets(): void
    {
        $admin = User::factory()->admin()->create();
        DnsCluster::query()->create([
            'name' => 'dns-local', 'location' => 'test', 'enabled' => true, 'last_health_status' => 'healthy',
            'api_url' => 'https://dns-api.example.test:8444', 'api_key' => 'private-test-key', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']],
        ]);
        $operation = Operation::query()->create([
            'actor_id' => $admin->id, 'type' => 'platform_dns_identity.update', 'status' => 'pending',
            'input' => ['settings_id' => 1, 'revision' => 1],
        ]);
        PlatformDnsSetting::query()->create([
            'id' => 1, ...$this->validPayload(), 'cluster_targets' => ['tehran'], 'revision' => 1,
        ]);

        try {
            (new ApplyPlatformDnsSettings($operation->id))->handle(app(PowerDnsClient::class));
            $this->fail('Expected reconciliation without a matching cluster to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No enabled, healthy DNS cluster matches', $exception->getMessage());
        }

        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertDatabaseCount('platform_dns_deployments', 0);
    }

    public function test_dns_cluster_normalizes_its_api_url_to_a_platform_target(): void
    {
        $cluster = new DnsCluster(['api_url' => 'https://DNS-API-1.ops.example.test:8444/path']);
        $this->assertSame('dns-api-1.ops.example.test:8444', $cluster->apiTarget());

        $cluster->api_url = 'https://[2001:db8::53]:8444';
        $this->assertSame('[2001:db8::53]:8444', $cluster->apiTarget());
    }

    public function test_dns_identity_validates_configured_address_families(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->validPayload();
        $payload['nameservers'][0]['ipv4'] = 'not-ipv4';
        $payload['nameservers'][0]['ipv6'] = 'not-ipv6';

        $this->actingAs($admin)->postJson('/api/admin/system/settings/dns/validate', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nameservers.0.ipv4', 'nameservers.0.ipv6']);
    }

    public function test_dns_identity_accepts_ipv4_only_nameserver_glue(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->validPayload();
        $payload['nameservers'] = collect($payload['nameservers'])
            ->map(fn (array $nameserver): array => [...$nameserver, 'ipv6' => null])
            ->all();

        $this->actingAs($admin)->postJson('/api/admin/system/settings/dns/validate', $payload)
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $settings = PlatformDnsSetting::query()->create(['id' => 1, ...$payload, 'revision' => 1]);
        $glue = collect(PlatformDnsZone::render($settings))
            ->whereIn('name', ['ns1.cdnf.test.', 'ns2.cdnf.test.']);

        $this->assertSame(['A'], $glue->pluck('type')->unique()->values()->all());
    }

    public function test_dns_identity_update_requires_confirmation_bound_to_the_exact_preview(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->validPayload();
        $token = $this->actingAs($admin)->postJson('/api/admin/system/settings/dns/validate', $payload)
            ->assertOk()->json('data.confirmation_token');
        $this->actingAs($admin)->patchJson('/api/admin/system/settings/dns', $payload)->assertConflict();
        $this->actingAs($admin)->patchJson('/api/admin/system/settings/dns', [
            ...$payload, 'default_ttl' => 600, 'confirmation_token' => $token,
        ])->assertConflict();
        $this->assertDatabaseCount('platform_dns_settings', 0);
    }

    public function test_platform_proxy_hostname_contains_only_registered_listener_ready_edges(): void
    {
        $settings = PlatformDnsSetting::query()->create(['id' => 1, ...$this->validPayload(), 'revision' => 1]);
        $ready = Edge::query()->create([
            'name' => 'ready-edge', 'country_code' => 'IR', 'continent_code' => 'AS',
            'management_ipv4' => '203.0.113.40', 'management_ipv6' => '2001:db8::40', 'registered_at' => now(),
            'last_heartbeat_at' => now(), 'capacity' => ['listener_ready' => true],
        ]);
        $unready = Edge::query()->create([
            'name' => 'unready-edge', 'country_code' => 'IR', 'continent_code' => 'AS',
            'management_ipv4' => '203.0.113.41', 'management_ipv6' => '2001:db8::41', 'registered_at' => now(),
            'last_heartbeat_at' => now(), 'capacity' => ['listener_ready' => false],
        ]);
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $ready->cells()->create([
            'edge_pool_id' => $pool->id, 'name' => $pool->name, 'status' => 'ready',
        ]);
        $unready->cells()->create([
            'edge_pool_id' => $pool->id, 'name' => $pool->name, 'status' => 'ready',
        ]);
        $pool->endpoints()->create(['edge_id' => $ready->id, 'ipv4' => '8.8.8.8', 'ipv6' => '2606:4700:4700::1111', 'revision' => 1, 'gateway_revision' => 1, 'gateway_state' => 'ready']);
        $pool->endpoints()->create(['edge_id' => $unready->id, 'ipv4' => '8.8.4.4', 'ipv6' => '2606:4700:4700::1001', 'revision' => 1, 'gateway_revision' => 1, 'gateway_state' => 'ready']);

        $proxyRows = collect(PlatformDnsZone::render($settings))->where('name', 'proxy.cdnf.test.');
        $this->assertSame(['LUA'], $proxyRows->pluck('type')->unique()->values()->all());
        $content = collect($proxyRows->first()['records'])->pluck('content')->implode(' ');
        $this->assertStringContainsString('countryCode()', $content);
        $this->assertStringContainsString('continentCode()', $content);
        $this->assertStringContainsString('dblookup', $content);
        $this->assertStringContainsString('pickhashed', $content);
        $addressRows = collect(PlatformDnsZone::render($settings))->flatMap(fn (array $row): array => $row['records']);
        $addresses = $addressRows->pluck('content');
        $this->assertTrue($addresses->contains('8.8.8.8'));
        $this->assertTrue($addresses->contains('2606:4700:4700::1111'));
        $this->assertFalse($addresses->contains('8.8.4.4'));
    }

    public function test_platform_proxy_hostname_publishes_an_ipv4_only_ready_cell(): void
    {
        $settings = PlatformDnsSetting::query()->create(['id' => 1, ...$this->validPayload(), 'revision' => 1]);
        $edge = Edge::query()->create([
            'name' => 'ipv4-only-edge', 'country_code' => 'IR', 'continent_code' => 'AS',
            'management_ipv4' => '203.0.113.50', 'management_ipv6' => null, 'registered_at' => now(),
            'last_heartbeat_at' => now(), 'capacity' => ['listener_ready' => true],
        ]);
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $edge->cells()->create([
            'edge_pool_id' => $pool->id, 'name' => $pool->name, 'status' => 'ready',
        ]);
        $pool->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => '8.8.8.8', 'revision' => 1, 'gateway_revision' => 1, 'gateway_state' => 'ready']);

        $addressRows = collect(PlatformDnsZone::render($settings))->flatMap(fn (array $row): array => $row['records']);
        $this->assertTrue($addressRows->pluck('content')->contains('8.8.8.8'));
    }

    public function test_domain_user_cannot_read_dns_identity_or_other_users_operation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $operation = Operation::query()->create(['actor_id' => $owner->id, 'type' => 'test', 'status' => 'pending', 'input' => []]);

        $this->actingAs($other)->getJson('/api/admin/system/settings/dns')->assertForbidden();
        $this->actingAs($other)->getJson("/api/operations/$operation->id")->assertForbidden();
    }

    private function validPayload(): array
    {
        return [
            'platform_domain' => 'cdnf.test',
            'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [
                ['hostname' => 'ns1.cdnf.test', 'ipv4' => '8.8.8.8', 'ipv6' => '2001:4860:4860::8888'],
                ['hostname' => 'ns2.cdnf.test', 'ipv4' => '1.1.1.1', 'ipv6' => '2606:4700:4700::1111'],
            ],
            'soa_primary' => 'ns1.cdnf.test',
            'soa_mailbox' => 'hostmaster.cdnf.test',
            'soa_refresh' => 3600,
            'soa_retry' => 600,
            'soa_expire' => 1209600,
            'soa_minimum_ttl' => 300,
            'default_ttl' => 300,
            'cluster_targets' => ['pdns-auth:8081'],
        ];
    }
}
