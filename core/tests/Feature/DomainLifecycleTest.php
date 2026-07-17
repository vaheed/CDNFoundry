<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Jobs\DeprovisionDnsZone;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\VerifyDomainNameservers;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\NameserverResolver;
use App\Support\PowerDnsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DomainLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_platform_nameservers_include_ipv4_and_ipv6_glue(): void
    {
        $this->platformIdentity();
        $this->getJson('/api/nameservers')->assertOk()->assertJsonPath('data.0.hostname', 'ns1.cdnf.test')
            ->assertJsonPath('data.0.ipv4', '192.0.2.1')->assertJsonPath('data.0.ipv6', '2001:db8::1');
    }

    public function test_nameserver_verification_is_asynchronous_coalesced_and_uses_exact_delegation(): void
    {
        Queue::fake();
        $this->platformIdentity();
        [$user, $domain] = $this->ownedDomain();
        $first = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/verify-nameservers")->assertAccepted();
        $second = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/verify-nameservers")->assertAccepted();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        Queue::assertPushed(VerifyDomainNameservers::class, 1);

        $resolver = new class extends NameserverResolver
        {
            public function resolve(string $domain): array
            {
                return ['ns1.cdnf.test', 'ns2.cdnf.test'];
            }
        };
        (new VerifyDomainNameservers($domain->id))->handle($resolver);
        $this->assertNotNull($domain->refresh()->nameservers_verified_at);
        $this->assertNull($domain->nameservers_verified_by);
        $this->assertSame('succeeded', Operation::findOrFail($first->json('data.id'))->status);
    }

    public function test_failed_verification_preserves_pending_state_and_visible_failure(): void
    {
        $this->platformIdentity();
        [, $domain] = $this->ownedDomain();
        $operation = Operation::query()->create(['type' => 'domain.nameservers_verify', 'status' => 'pending', 'input' => ['domain_id' => $domain->id]]);
        $resolver = new class extends NameserverResolver
        {
            public function resolve(string $domain): array
            {
                return ['wrong.example.net'];
            }
        };
        try {
            (new VerifyDomainNameservers($domain->id))->handle($resolver);
            $this->fail('Mismatched delegation must fail verification.');
        } catch (\RuntimeException) {
        }

        $this->assertNull($domain->refresh()->nameservers_verified_at);
        $this->assertSame(DomainLifecycleState::PendingVerification, $domain->lifecycle_state);
        $this->assertSame('failed', $operation->refresh()->status);
    }

    public function test_force_verification_is_admin_only_audited_and_cannot_revive_deprovisioning(): void
    {
        $admin = User::factory()->admin()->create();
        [$user, $domain] = $this->ownedDomain();
        $this->actingAs($user)->postJson("/api/admin/domains/{$domain->id}/force-verify")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/force-verify")->assertOk();
        $this->assertSame($admin->id, $domain->refresh()->nameservers_verified_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.nameservers_force_verified', 'subject_id' => (string) $domain->id]);

        $domain->update(['lifecycle_state' => DomainLifecycleState::Deprovisioning, 'deprovision_after' => now()->addDay()]);
        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/force-verify")->assertConflict();
    }

    public function test_force_verification_bypasses_only_delegation_and_activation_still_reconciles_normally(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        [, $domain] = $this->ownedDomain();
        DnsCluster::query()->create($this->clusterData());

        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/force-verify")->assertOk();
        $this->assertSame(DomainLifecycleState::PendingVerification, $domain->refresh()->lifecycle_state);
        Queue::assertNothingPushed();

        $response = $this->actingAs($admin)->postJson("/api/domains/{$domain->id}/activate")->assertAccepted();
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame(DomainLifecycleState::Active, $domain->refresh()->lifecycle_state);
        Queue::assertPushed(ReconcileDnsZone::class, 1);
    }

    public function test_activation_requires_verification_and_is_idempotent(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/activate")->assertConflict();
        $domain->update(['nameservers_verified_at' => now()]);
        DnsCluster::query()->create($this->clusterData());

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/activate")->assertAccepted();
        $this->assertSame(DomainLifecycleState::Active, $domain->refresh()->lifecycle_state);
        $this->assertSame(2, $domain->revision);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/activate")->assertAccepted();
        $this->assertSame(2, $domain->refresh()->revision);
        Queue::assertPushed(ReconcileDnsZone::class);
    }

    public function test_activation_requires_an_enabled_healthy_dns_cluster(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $domain->update(['nameservers_verified_at' => now()]);
        DnsCluster::query()->create([...$this->clusterData(), 'enabled' => false]);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/activate")
            ->assertConflict()->assertJsonPath('message', 'Enable at least one healthy DNS cluster before activation.');
        $this->assertSame(DomainLifecycleState::PendingVerification, $domain->refresh()->lifecycle_state);
        Queue::assertNothingPushed();
    }

    public function test_deletion_creates_every_target_tombstone_and_due_job_removes_runtime_zone(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $first = DnsCluster::query()->create($this->clusterData());
        DnsCluster::query()->create([...$this->clusterData(), 'name' => 'dns-us-1', 'enabled' => false]);
        DnsDeployment::query()->create([
            'domain_id' => $domain->id, 'dns_cluster_id' => $first->id, 'desired_revision' => 1,
            'deployed_revision' => 1, 'status' => 'succeeded', 'active_rrsets' => [['type' => 'A']],
        ]);

        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}")->assertAccepted();
        $this->assertDatabaseCount('dns_deployments', 2);
        $this->assertSame(2, DnsDeployment::query()->where('tombstone', true)->count());
        $domain->update(['deprovision_after' => now()->subSecond()]);
        Http::fake(['*' => Http::response([], 204)]);
        (new DeprovisionDnsZone($domain->id))->handle(app(PowerDnsClient::class));

        $this->assertSame(2, DnsDeployment::query()->where('status', 'deprovisioned')->whereNotNull('deprovisioned_at')->count());
        $this->assertSame([], DnsDeployment::query()->where('dns_cluster_id', $first->id)->firstOrFail()->active_rrsets);
        Http::assertSentCount(2);
    }

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.com', 'display_name' => 'example.com', 'lifecycle_state' => DomainLifecycleState::PendingVerification, 'revision' => 1]);
        $domain->users()->attach($user);

        return [$user, $domain];
    }

    private function clusterData(): array
    {
        return [
            'name' => 'dns-eu-1', 'location' => 'eu-test', 'enabled' => true, 'last_health_status' => 'healthy',
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
