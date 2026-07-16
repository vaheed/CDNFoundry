<?php

namespace Tests\Feature;

use App\Jobs\ReconcileDnsZone;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\DnsRecordData;
use App\Support\PowerDnsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DnsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_cluster_without_exposing_encrypted_api_key(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain();
        $response = $this->actingAs($admin)->postJson('/api/admin/dns/clusters', $this->clusterData())
            ->assertCreated()->assertJsonMissing(['api_key' => 'private-test-key']);

        $cluster = DnsCluster::findOrFail($response->json('data.id'));
        $this->assertNotSame('private-test-key', $cluster->getRawOriginal('api_key'));
        $this->assertSame('private-test-key', $cluster->api_key);
        Queue::assertPushed(ReconcileDnsZone::class, fn ($job): bool => $job->domainId === $domain->id);
        $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$cluster->id}/disable")->assertOk()->assertJsonPath('data.enabled', false);
        $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$cluster->id}/enable")->assertOk()->assertJsonPath('data.enabled', true);
    }

    public function test_reconciliation_renders_and_activates_latest_zone_deterministically(): void
    {
        $domain = $this->domain();
        $domain->dnsRecords()->create(DnsRecordData::validate([
            'type' => 'A', 'name' => 'www', 'content' => '192.0.2.10', 'ttl' => 300,
        ], $domain->name));
        $cluster = DnsCluster::query()->create($this->clusterData());
        $this->platformIdentity();
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response([], 404);
            }

            return Http::response([], $request->method() === 'POST' ? 201 : 204);
        });

        (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));

        $deployment = DnsDeployment::query()->whereBelongsTo($domain)->whereBelongsTo($cluster, 'cluster')->firstOrFail();
        $this->assertSame('succeeded', $deployment->status);
        $this->assertSame($domain->revision, $deployment->deployed_revision);
        $this->assertNotEmpty($deployment->active_rrsets);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && collect($request->data()['rrsets'] ?? [])->contains(fn (array $rrset): bool => ($rrset['type'] ?? null) === 'A' && ($rrset['records'][0]['content'] ?? null) === '192.0.2.10'));
    }

    public function test_failed_target_keeps_previous_valid_snapshot_and_visible_error(): void
    {
        $domain = $this->domain();
        $cluster = DnsCluster::query()->create($this->clusterData());
        $this->platformIdentity();
        $previous = [['name' => 'example.com.', 'type' => 'A', 'ttl' => 300, 'records' => [['content' => '192.0.2.1', 'disabled' => false]]]];
        DnsDeployment::query()->create([
            'domain_id' => $domain->id, 'dns_cluster_id' => $cluster->id, 'desired_revision' => 1,
            'deployed_revision' => 1, 'status' => 'succeeded', 'active_checksum' => str_repeat('a', 64), 'active_rrsets' => $previous,
        ]);
        $domain->update(['revision' => 2]);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        try {
            (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));
            $this->fail('The failed cluster should make the job retryable.');
        } catch (\RuntimeException) {
            // Expected: queue retries remain available while deployment state records the target failure.
        }

        $deployment = DnsDeployment::firstOrFail();
        $this->assertSame('failed', $deployment->status);
        $this->assertSame(1, $deployment->deployed_revision);
        $this->assertEquals($previous, $deployment->active_rrsets);
        $this->assertStringContainsString('503', $deployment->last_error);
    }

    public function test_manual_reconcile_is_coalesced_to_one_pending_operation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $domain = $this->domain();
        $domain->users()->attach($user);

        $first = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/reconcile")->assertAccepted();
        $second = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/reconcile")->assertAccepted();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('operations', 1);
        Queue::assertPushed(ReconcileDnsZone::class, 1);
    }

    private function domain(): Domain
    {
        return Domain::query()->create(['name' => 'example.com', 'display_name' => 'example.com', 'lifecycle_state' => 'active', 'revision' => 1]);
    }

    private function clusterData(): array
    {
        return [
            'name' => 'dns-eu-1', 'location' => 'eu-test', 'enabled' => true,
            'api_url' => 'http://pdns.test', 'api_key' => 'private-test-key', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']], 'capacity_zones' => 1000,
        ];
    }

    private function platformIdentity(): void
    {
        PlatformDnsSetting::query()->create([
            'id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test', 'ipv4' => '192.0.2.1', 'ipv6' => '2001:db8::1'], ['hostname' => 'ns2.cdnf.test', 'ipv4' => '192.0.2.2', 'ipv6' => '2001:db8::2']],
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test', 'soa_refresh' => 3600,
            'soa_retry' => 600, 'soa_expire' => 604800, 'soa_minimum_ttl' => 300, 'default_ttl' => 300,
            'cluster_targets' => ['pdns.test'],
        ]);
    }
}
