<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Http\Requests\Admin\PlatformDnsSettingsRequest;
use App\Jobs\AllocateNameserverPair;
use App\Jobs\DeprovisionDnsZone;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\VerifyDomainNameservers;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\PlatformDnsDeployment;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\DomainNameserverVerification;
use App\Support\NameserverResolver;
use App\Support\PlatformDnsZone;
use App\Support\PowerDnsClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AllocatesNameserverPairs;
use Tests\TestCase;

class DomainClaimTakeoverTest extends TestCase
{
    use AllocatesNameserverPairs;
    use RefreshDatabase;

    /** Submit a claim, then run its queued pair allocation against a parent publishing a registrar delegation. */
    private function claim(User $user, string $name): Domain
    {
        return $this->allocatePair(Domain::createPendingFor($user, $name));
    }

    public function test_a_squatter_who_claims_first_cannot_block_or_beat_the_real_registrant(): void
    {
        Queue::fake();
        $this->platform(2);
        DnsCluster::query()->create($this->clusterData());
        $squatter = User::factory()->create();
        $owner = User::factory()->create();
        $squat = $this->claim($squatter, 'victim.example.com');
        $claim = $this->claim($owner, 'victim.example.com');
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
        $held = $this->claim($previous, 'sold.example.com')->refresh();
        $held->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()])->save();
        $served = [['name' => 'sold.example.com.', 'type' => 'A', 'ttl' => 300, 'records' => [['content' => '192.0.2.99', 'disabled' => false]]]];
        DnsDeployment::query()->create(['domain_id' => $held->id, 'dns_cluster_id' => $cluster->id, 'desired_revision' => 1, 'deployed_revision' => 1, 'status' => 'succeeded', 'active_rrsets' => $served]);
        $held->dnsRecords()->create(['type' => 'A', 'name' => 'sold.example.com', 'content' => '192.0.2.99', 'ttl' => 300, 'mode' => 'dns_only', 'content_hash' => 'h']);

        $claim = $this->claim($registrant, 'sold.example.com')->refresh();
        $this->assertNotEquals($held->refresh()->assignedNameservers(), $claim->delegation_nameservers, 'a new claim gets a pair other than the current delegation');
        $this->assertSame(DomainLifecycleState::Active, $held->lifecycle_state, 'a pending claim changes nothing for the holder');

        (new VerifyDomainNameservers($claim->id))->handle($this->resolverAnswering($claim->delegation_nameservers));

        $this->assertSame(DomainLifecycleState::Active, $claim->refresh()->lifecycle_state);
        $this->assertSame(DomainLifecycleState::Deprovisioning, $held->refresh()->lifecycle_state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.taken_over', 'subject_id' => (string) $held->id]);
        $this->assertSame([], $held->users()->pluck('users.id')->all(), 'the previous owner loses access at the transfer');
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

    public function test_an_exhausted_pool_fails_safely_and_never_reuses_a_reserved_pair(): void
    {
        Queue::fake();
        $this->platform(1);
        $first = $this->claim(User::factory()->create(), 'crowded.example.com');
        $first->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()])->save();

        $second = $this->claim(User::factory()->create(), 'crowded.example.com');

        $this->assertNull($second->delegation_nameservers, 'no pair is free, so nothing is assigned');
        $operation = Operation::query()->where('type', 'domain.nameservers_verify')->where('input->domain_id', $second->id)->sole();
        $this->assertSame('failed', $operation->status);
        $this->assertSame('nameserver_pairs_unavailable', $operation->result['code']);
        $this->assertSame(DomainLifecycleState::Active, $first->refresh()->lifecycle_state);
    }

    public function test_the_pair_the_parent_publishes_now_is_never_assigned(): void
    {
        Queue::fake();
        $this->platform(2);
        $domain = Domain::createPendingFor(User::factory()->create(), 'delegated.example.com');

        $this->allocatePair($domain, ['ns1.cdnf.test', 'ns2.cdnf.test']);

        $this->assertSame(['ns3.cdnf.test', 'ns4.cdnf.test'], $domain->delegation_nameservers);
    }

    public function test_an_unknown_parent_delegation_assigns_nothing(): void
    {
        Queue::fake();
        $this->platform(2);
        $domain = Domain::createPendingFor(User::factory()->create(), 'unknown.example.com');
        $resolver = new class extends NameserverResolver
        {
            public function delegation(string $domain): array
            {
                throw new \RuntimeException('Parent authorities disagree on the delegation. Retry after propagation.');
            }
        };

        try {
            (new AllocateNameserverPair($domain->id))->handle($resolver, app(DomainNameserverVerification::class));
            $this->fail('An unreliable parent delegation must not allocate.');
        } catch (\RuntimeException) {
            $this->assertNull($domain->refresh()->delegation_nameservers);
        }
    }

    public function test_a_pair_is_not_assigned_before_the_platform_zone_publishes_it(): void
    {
        Queue::fake();
        $this->platform(2);
        $cluster = DnsCluster::query()->create($this->clusterData());
        PlatformDnsDeployment::query()->create([
            'dns_cluster_id' => $cluster->id, 'status' => 'succeeded', 'desired_revision' => 1, 'deployed_revision' => 1,
            'active_rrsets' => [
                ['name' => 'ns1.cdnf.test.', 'type' => 'A', 'ttl' => 300, 'records' => []],
                ['name' => 'ns2.cdnf.test.', 'type' => 'A', 'ttl' => 300, 'records' => []],
            ],
        ]);
        $domain = Domain::createPendingFor(User::factory()->create(), 'early.example.com');

        $this->allocatePair($domain, ['ns1.registrar.test', 'ns2.registrar.test']);

        $this->assertSame(['ns1.cdnf.test', 'ns2.cdnf.test'], $domain->delegation_nameservers, 'only the published pair is offered');
    }

    public function test_two_pending_claims_never_hold_the_same_pair_and_pairs_are_shared_across_names(): void
    {
        Queue::fake();
        $this->platform(3);
        $a = $this->claim(User::factory()->create(), 'one.example.com');
        $b = $this->claim(User::factory()->create(), 'one.example.com');
        $c = $this->claim(User::factory()->create(), 'one.example.com');
        $other = $this->claim(User::factory()->create(), 'two.example.com');

        $this->assertCount(3, collect([$a, $b, $c])->map(fn (Domain $claim): string => implode(',', $claim->delegation_nameservers))->unique());
        $this->assertNotNull($other->delegation_nameservers);
        $this->expectException(QueryException::class);
        Domain::query()->whereKey($b->id)->update(['delegation_nameservers' => $a->delegation_nameservers]);
    }

    public function test_a_superseded_applicant_can_claim_the_name_again(): void
    {
        Queue::fake();
        $this->platform(3);
        $user = User::factory()->create();
        $claim = $this->claim($user, 'again.example.com');
        $claim->forceFill(['lifecycle_state' => DomainLifecycleState::Deprovisioning])->save();
        Domain::query()->create(['name' => 'again.example.com', 'display_name' => 'holder', 'lifecycle_state' => DomainLifecycleState::Active, 'revision' => 2]);

        $fresh = $this->claim($user, 'again.example.com');

        $this->assertNotSame($claim->id, $fresh->id);
        $this->assertSame(DomainLifecycleState::PendingVerification, $fresh->lifecycle_state);
    }

    public function test_canonical_variants_cannot_bypass_claim_limits(): void
    {
        Queue::fake();
        $this->platform(2);
        $user = User::factory()->create();
        $this->claim($user, 'Mixed.Example.COM.');

        $this->expectException(ValidationException::class);
        Domain::createPendingFor($user, 'mixed.example.com');
    }

    public function test_an_invalidated_claim_cannot_be_activated_by_its_old_operation(): void
    {
        Queue::fake();
        $this->platform(2);
        DnsCluster::query()->create($this->clusterData());
        $loser = $this->claim(User::factory()->create(), 'race.example.com');
        $winner = $this->claim(User::factory()->create(), 'race.example.com');
        (new VerifyDomainNameservers($winner->id))->handle($this->resolverAnswering($winner->delegation_nameservers));

        try {
            (new VerifyDomainNameservers($loser->id))->handle($this->resolverAnswering($loser->delegation_nameservers));
        } catch (\Throwable) {
        }

        $this->assertNull($loser->refresh()->nameservers_verified_at);
        $this->assertSame(DomainLifecycleState::Active, $winner->refresh()->lifecycle_state);
        $this->assertSame(1, Domain::query()->where('name', 'race.example.com')->where('lifecycle_state', 'active')->count());
    }

    public function test_one_user_cannot_hold_two_claims_on_the_same_name(): void
    {
        Queue::fake();
        $this->platform(2);
        $user = User::factory()->create();
        $this->claim($user, 'twice.example.com');

        $this->expectException(ValidationException::class);
        $this->claim($user, 'twice.example.com');
    }

    public function test_a_competing_claim_defers_to_the_holder_and_never_publishes_a_zone(): void
    {
        Queue::fake();
        $this->platform(2);
        DnsCluster::query()->create($this->clusterData());
        $held = $this->claim(User::factory()->create(), 'deferred.example.com')->refresh();
        $held->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()])->save();
        $claim = $this->claim(User::factory()->create(), 'deferred.example.com')->refresh();
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

    public function test_customer_pairs_get_address_records_but_never_join_the_apex_ns_set(): void
    {
        $this->platform(1);
        $settings = PlatformDnsSetting::query()->findOrFail(1);
        $settings->update(['customer_nameserver_pairs' => [[
            ['hostname' => 'ns9.cdnf.test', 'ipv4' => '8.8.8.9', 'ipv6' => '2001:4860:4860::9'],
            ['hostname' => 'ns10.cdnf.test', 'ipv4' => '8.8.8.10', 'ipv6' => null],
        ]]]);

        $rrsets = collect(PlatformDnsZone::render($settings->refresh()));

        $this->assertSame('8.8.8.9', $rrsets->firstWhere(fn (array $rrset): bool => $rrset['name'] === 'ns9.cdnf.test.' && $rrset['type'] === 'A')['records'][0]['content']);
        $this->assertNotNull($rrsets->firstWhere(fn (array $rrset): bool => $rrset['name'] === 'ns9.cdnf.test.' && $rrset['type'] === 'AAAA'));
        $this->assertNull($rrsets->firstWhere(fn (array $rrset): bool => $rrset['name'] === 'ns10.cdnf.test.' && $rrset['type'] === 'AAAA'));
        $apexNs = $rrsets->firstWhere(fn (array $rrset): bool => $rrset['name'] === 'cdnf.test.' && $rrset['type'] === 'NS');
        $this->assertSame(['ns1.cdnf.test.', 'ns2.cdnf.test.'], collect($apexNs['records'])->pluck('content')->all());
    }

    public function test_the_pool_rejects_hostnames_outside_the_platform_wildcards_and_private_addresses(): void
    {
        $base = [
            'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test', 'ipv4' => '8.8.8.1'], ['hostname' => 'ns2.cdnf.test', 'ipv4' => '8.8.8.2']],
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test', 'soa_refresh' => 3600, 'soa_retry' => 600,
            'soa_expire' => 604800, 'soa_minimum_ttl' => 300, 'default_ttl' => 300, 'cluster_targets' => ['pdns.test'],
        ];
        $pair = fn (string $a, string $addressA, string $b, string $addressB): array => [[['hostname' => $a, 'ipv4' => $addressA], ['hostname' => $b, 'ipv4' => $addressB]]];

        foreach ([
            'outside platform' => $pair('ns1.other.test', '8.8.8.1', 'ns2.cdnf.test', '8.8.8.2'),
            'private address' => $pair('ns1.cdnf.test', '10.0.0.1', 'ns2.cdnf.test', '8.8.8.2'),
            'duplicate hostname' => $pair('ns1.cdnf.test', '8.8.8.1', 'ns1.cdnf.test', '8.8.8.2'),
        ] as $case => $pairs) {
            try {
                PlatformDnsSettingsRequest::validateInput([...$base, 'customer_nameserver_pairs' => $pairs]);
                $this->fail("Pool accepted: {$case}");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors(), $case);
            }
        }
        $defaulted = PlatformDnsSettingsRequest::validateInput($base);
        $this->assertCount(1, $defaulted['customer_nameserver_pairs']);
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
