<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\TestDnsCluster;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\User;
use App\Support\PowerDnsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminDnsOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cluster_health_test_is_async_coalesced_and_records_result(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $cluster = DnsCluster::query()->create($this->clusterData());

        $first = $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$cluster->id}/test")->assertAccepted();
        $second = $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$cluster->id}/test")->assertAccepted();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        Queue::assertPushed(TestDnsCluster::class, 1);

        Http::fake(['*' => Http::response(['id' => 'localhost'])]);
        (new TestDnsCluster($first->json('data.id')))->handle(app(PowerDnsClient::class));
        $this->assertSame('healthy', $cluster->refresh()->last_health_status);
        $this->assertSame('succeeded', Operation::findOrFail($first->json('data.id'))->status);
    }

    public function test_new_cluster_is_disabled_tested_asynchronously_and_cannot_enable_before_success(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/dns/clusters', [
            ...$this->clusterData(),
            'enabled' => true,
        ])->assertAccepted();

        $cluster = DnsCluster::query()->findOrFail($response->json('data.id'));
        $this->assertFalse($cluster->enabled);
        Queue::assertPushed(TestDnsCluster::class, 1);
        $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$cluster->id}/enable")->assertConflict();

        $cluster->update(['last_health_status' => 'healthy']);
        $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$cluster->id}/enable")
            ->assertOk()->assertJsonPath('data.enabled', true);
    }

    public function test_global_reconciliation_is_admin_only_coalesced_and_dispatches_active_domains(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Domain::query()->create(['name' => 'active.test', 'display_name' => 'active.test', 'lifecycle_state' => DomainLifecycleState::Active]);
        Domain::query()->create(['name' => 'disabled.test', 'display_name' => 'disabled.test', 'lifecycle_state' => DomainLifecycleState::Disabled]);

        $this->actingAs($user)->postJson('/api/admin/dns/reconcile')->assertForbidden();
        $first = $this->actingAs($admin)->postJson('/api/admin/dns/reconcile')->assertAccepted();
        $second = $this->actingAs($admin)->postJson('/api/admin/dns/reconcile')->assertAccepted();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        Queue::assertPushed(ReconcileAllDnsZones::class, 1);

        (new ReconcileAllDnsZones($first->json('data.id')))->handle();
        Queue::assertPushed(ReconcileDnsZone::class, 1);
        $this->assertSame(1, Operation::findOrFail($first->json('data.id'))->result['domains_dispatched']);
    }

    public function test_deployment_lists_are_bounded_and_failures_are_filtered(): void
    {
        $admin = User::factory()->admin()->create();
        $cluster = DnsCluster::query()->create($this->clusterData());
        $failed = Domain::query()->create(['name' => 'failed.test', 'display_name' => 'failed.test']);
        $healthy = Domain::query()->create(['name' => 'healthy.test', 'display_name' => 'healthy.test']);
        DnsDeployment::query()->create(['domain_id' => $failed->id, 'dns_cluster_id' => $cluster->id, 'status' => 'failed', 'last_error' => 'connection refused', 'active_rrsets' => [['content' => 'must not leak into list']]]);
        DnsDeployment::query()->create(['domain_id' => $healthy->id, 'dns_cluster_id' => $cluster->id, 'status' => 'succeeded']);

        $this->actingAs($admin)->getJson('/api/admin/dns/deployments')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.active_rrsets');
        $this->actingAs($admin)->getJson('/api/admin/dns/failed-deployments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.domain.name', 'failed.test');
    }

    private function clusterData(): array
    {
        return [
            'name' => 'dns-eu-1', 'location' => 'eu-test', 'enabled' => true,
            'api_url' => 'http://pdns.test', 'api_key' => 'private-test-key', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.test'], ['hostname' => 'ns2.test']], 'capacity_zones' => 1000,
        ];
    }
}
