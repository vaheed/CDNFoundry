<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Jobs\DeprovisionDnsZone;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\VerifyDomainNameservers;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\NameserverResolver;
use App\Support\PlatformDnsZone;
use App\Support\PowerDnsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DomainClaimTakeoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_squatter_who_claims_first_cannot_block_or_beat_the_real_registrant(): void
    {
        Queue::fake();
        $this->platform(2);
        DnsCluster::query()->create($this->clusterData());
        $squatter = User::factory()->create();
        $owner = User::factory()->create();
        $squat = Domain::createPendingFor($squatter, 'victim.example.com');
        $claim = Domain::createPendingFor($owner, 'victim.example.com');
        $squat->refresh();
        $claim->refresh();
        $this->assertNotEquals($squat->delegation_nameservers, $claim->delegation_nameservers);
        $this->assertSame(2, Domain::query()->where('name', 'victim.example.com')->count());

        // The squatter cannot prove control: the registrant never delegates to the squatter's pair.
        try {
            (new VerifyDomainNameservers($squat->id))->handle($this->resolverAnswering($claim->delegation_nameservers));
            $this->fail('The registrant\'s delegation must not verify the squatter.');
        } catch (\RuntimeException) {
            $this->assertNull($squat->refresh()->nameservers_verified_at);
        }

        (new VerifyDomainNameservers($claim->id))->handle($this->resolverAnswering($claim->delegation_nameservers));

        $this->assertSame(DomainLifecycleState::Active, $claim->refresh()->lifecycle_state);
        $this->assertSame(DomainLifecycleState::Deprovisioning, $squat->refresh()->lifecycle_state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.claim_superseded', 'subject_id' => (string) $squat->id]);
        Queue::assertPushed(DeprovisionDnsZone::class, fn ($job): bool => $job->domainId === $squat->id);
    }

    public function test_a_new_registrant_takes_the_name_from_the_previous_owner_without_inheriting_anything(): void
    {
        Queue::fake();
        $this->platform(2);
        $cluster = DnsCluster::query()->create($this->clusterData());
        $previous = User::factory()->create();
        $registrant = User::factory()->create();
        $held = Domain::createPendingFor($previous, 'sold.example.com')->refresh();
        $held->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()])->save();
        $served = [['name' => 'sold.example.com.', 'type' => 'A', 'ttl' => 300, 'records' => [['content' => '192.0.2.99', 'disabled' => false]]]];
        DnsDeployment::query()->create(['domain_id' => $held->id, 'dns_cluster_id' => $cluster->id, 'desired_revision' => 1, 'deployed_revision' => 1, 'status' => 'succeeded', 'active_rrsets' => $served]);
        $held->dnsRecords()->create(['type' => 'A', 'name' => 'sold.example.com', 'content' => '192.0.2.99', 'ttl' => 300, 'mode' => 'dns_only', 'content_hash' => 'h']);

        $claim = Domain::createPendingFor($registrant, 'sold.example.com')->refresh();
        $this->assertNotEquals($held->refresh()->assignedNameservers(), $claim->delegation_nameservers, 'a new claim gets a pair other than the current delegation');
        $this->assertSame(DomainLifecycleState::Active, $held->lifecycle_state, 'a pending claim changes nothing for the holder');

        (new VerifyDomainNameservers($claim->id))->handle($this->resolverAnswering($claim->delegation_nameservers));

        $this->assertSame(DomainLifecycleState::Active, $claim->refresh()->lifecycle_state);
        $this->assertSame(DomainLifecycleState::Deprovisioning, $held->refresh()->lifecycle_state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.taken_over', 'subject_id' => (string) $held->id]);
        $this->assertSame(0, $claim->dnsRecords()->count(), 'the previous owner\'s records do not move');
        $this->assertSame(0, $claim->tlsCertificates()->count());
        $seeded = DnsDeployment::query()->where('domain_id', $claim->id)->firstOrFail();
        $this->assertEquals($served, $seeded->active_rrsets, 'seeded only so the first publish replaces the old record sets');
        $this->assertSame([$registrant->id], $claim->users()->pluck('users.id')->all());
    }

    public function test_the_previous_owners_retirement_never_deletes_the_new_holders_zone(): void
    {
        $this->platform(2);
        DnsCluster::query()->create($this->clusterData());
        $old = Domain::query()->create(['name' => 'shared-zone.example.com', 'display_name' => 'Old', 'lifecycle_state' => DomainLifecycleState::Deprovisioning, 'deprovision_after' => now()->subMinute(), 'revision' => 3]);
        Domain::query()->create(['name' => 'shared-zone.example.com', 'display_name' => 'New', 'lifecycle_state' => DomainLifecycleState::Active, 'revision' => 2]);
        Http::fake();
        Queue::fake();

        (new DeprovisionDnsZone($old->id))->handle(app(PowerDnsClient::class));

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        $this->assertSame('deprovisioned', DnsDeployment::query()->where('domain_id', $old->id)->firstOrFail()->status);
    }

    public function test_competing_claims_are_limited_by_the_free_nameserver_pairs(): void
    {
        Queue::fake();
        $this->platform(1);
        $first = Domain::createPendingFor(User::factory()->create(), 'crowded.example.com')->refresh();
        $first->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()])->save();

        try {
            Domain::createPendingFor(User::factory()->create(), 'crowded.example.com');
            $this->fail('With one pair and a holder, no pair is free for another claim.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
        $this->assertSame(1, Domain::query()->where('name', 'crowded.example.com')->count());
    }

    public function test_one_user_cannot_hold_two_claims_on_the_same_name(): void
    {
        Queue::fake();
        $this->platform(2);
        $user = User::factory()->create();
        Domain::createPendingFor($user, 'twice.example.com');

        $this->expectException(ValidationException::class);
        Domain::createPendingFor($user, 'twice.example.com');
    }

    public function test_a_competing_claim_defers_to_the_holder_and_never_publishes_a_zone(): void
    {
        Queue::fake();
        $this->platform(2);
        DnsCluster::query()->create($this->clusterData());
        $held = Domain::createPendingFor(User::factory()->create(), 'deferred.example.com')->refresh();
        $held->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()])->save();
        $claim = Domain::createPendingFor(User::factory()->create(), 'deferred.example.com')->refresh();
        Http::fake();

        $this->assertTrue($claim->isSecondaryClaim());
        (new ReconcileDnsZone($claim->id))->handle(app(PowerDnsClient::class));

        Http::assertNothingSent();
        Queue::assertPushed(VerifyDomainNameservers::class, fn ($job): bool => $job->domainId === $claim->id);
    }

    public function test_the_platform_zone_has_readable_nameservers_and_no_wildcard(): void
    {
        $this->platform(2);
        $names = collect(PlatformDnsZone::render(PlatformDnsSetting::query()->findOrFail(1)))->pluck('name');

        $this->assertTrue($names->contains('ns1.cdnf.test.'));
        $this->assertTrue($names->contains('ns4.cdnf.test.'));
        $this->assertSame([], $names->filter(fn (string $name): bool => str_starts_with($name, '*.'))->all());
    }

    private function resolverAnswering(array $answer): NameserverResolver
    {
        return new class($answer) extends NameserverResolver
        {
            public function __construct(private array $answer) {}

            public function resolve(string $domain): array
            {
                return $this->answer;
            }
        };
    }

    private function platform(int $pairs): void
    {
        PlatformDnsSetting::query()->create([
            'id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => collect(range(1, $pairs * 2))->map(fn (int $number): array => ['hostname' => "ns{$number}.cdnf.test", 'ipv4' => '8.8.8.'.$number])->all(),
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test', 'soa_refresh' => 3600, 'soa_retry' => 600,
            'soa_expire' => 604800, 'soa_minimum_ttl' => 300, 'default_ttl' => 300, 'cluster_targets' => ['pdns.test'],
        ]);
    }

    private function clusterData(): array
    {
        return [
            'name' => 'dns-eu-1', 'location' => 'eu-test', 'enabled' => true, 'last_health_status' => 'healthy',
            'api_url' => 'http://pdns.test', 'api_key' => 'private-test-key', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']], 'capacity_zones' => 1000,
        ];
    }
}
