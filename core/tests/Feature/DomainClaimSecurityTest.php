<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\Pages\CreateDomain;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\VerifyDomainNameservers;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\DomainNameTombstone;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\DomainName;
use App\Support\DomainNameserverVerification;
use App\Support\NameserverResolver;
use App\Support\PowerDnsClient;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DomainClaimSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoked_assignment_cannot_replay_cached_domain_response(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'replay.example.com', 'display_name' => 'Replay']);
        $domain->users()->attach($user);
        $key = (string) Str::uuid();
        $this->actingAs($user)->withHeader('Idempotency-Key', $key)
            ->patchJson("/api/domains/{$domain->id}", ['display_name' => 'Confidential label'])->assertOk();
        $domain->users()->detach($user);
        $this->actingAs($user)->withHeader('Idempotency-Key', $key)
            ->patchJson("/api/domains/{$domain->id}", ['display_name' => 'Confidential label'])->assertForbidden();
    }

    public function test_public_suffix_rules_and_idna_preserve_delegated_zone_support(): void
    {
        foreach (['com.br', 'co.za', 'github.io', 'foo.ck', 'example.com..', 'bücher.de..'] as $name) {
            try {
                DomainName::normalize($name);
                $this->fail("Invalid boundary accepted: {$name}");
            } catch (\InvalidArgumentException) {
            }
        }
        foreach (['www.ck', 'customer.github.io', 'child.example.com', '2.0.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa'] as $name) {
            $this->assertSame($name, DomainName::normalize($name));
        }
        $this->assertSame('xn--bcher-kva.de', DomainName::normalize('BÜCHER。DE。'));
    }

    public function test_filament_and_api_enforce_canonical_reclaim_cooldown(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        DomainNameTombstone::query()->create([
            'name' => 'xn--bcher-kva.de', 'source_domain_id' => 123,
            'deprovisioned_at' => now(), 'reclaim_after' => now()->addDay(),
        ]);
        $this->postJson('/api/domains', ['name' => 'BÜCHER.DE.'])->assertUnprocessable();
        Livewire::test(CreateDomain::class)->fillForm(['name' => 'BÜCHER.DE.'])
            ->call('create')->assertHasFormErrors(['name']);
        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('operations', 0);
        Queue::assertNothingPushed();
        $this->travel(2)->days();
        Livewire::test(CreateDomain::class)->fillForm(['name' => 'BÜCHER.DE.'])
            ->call('create')->assertHasNoFormErrors();
        $domain = Domain::query()->sole();
        $this->assertSame('xn--bcher-kva.de', $domain->name);
        $this->assertSame(DomainLifecycleState::PendingVerification, $domain->lifecycle_state);
        $this->assertNull($domain->nameservers_verified_at);
        $this->assertSame([$user->id], $domain->users()->pluck('users.id')->all());
    }

    public function test_duplicate_creation_rolls_back_without_assigning_the_other_applicant(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $domain = Domain::createPendingFor($owner, 'Example.COM.');
        $this->actingAs($attacker)->postJson('/api/domains', ['name' => 'example.com'])->assertUnprocessable();
        $this->getJson("/api/domains/{$domain->id}")->assertForbidden();
        $this->assertSame([$owner->id], $domain->users()->pluck('users.id')->all());
        $this->assertDatabaseCount('domains', 1);
    }

    public function test_queued_verification_cannot_restore_disabled_or_revoked_access(): void
    {
        Queue::fake();
        PlatformDnsSetting::query()->create([
            'id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']],
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test',
            'soa_refresh' => 3600, 'soa_retry' => 600, 'soa_expire' => 604800,
            'soa_minimum_ttl' => 300, 'default_ttl' => 300, 'cluster_targets' => [],
        ]);
        DnsCluster::query()->create([
            'name' => 'dns-test', 'location' => 'test', 'enabled' => true, 'last_health_status' => 'healthy',
            'api_url' => 'http://pdns.test', 'api_key' => 'test-only', 'server_id' => 'localhost',
            'nameservers' => [], 'capacity_zones' => 100,
        ]);
        foreach (['disabled_domain', 'deprovisioning', 'disabled_user', 'unassigned_user', 'cancelled_operation'] as $scenario) {
            $user = User::factory()->create();
            $domain = Domain::createPendingFor($user, str_replace('_', '-', $scenario).'.example.com');
            $operation = Operation::query()->where('type', 'domain.nameservers_verify')->where('input->domain_id', $domain->id)->sole();
            match ($scenario) {
                'disabled_domain' => $domain->update(['lifecycle_state' => DomainLifecycleState::Disabled, 'disabled_at' => now()]),
                'deprovisioning' => $domain->update(['lifecycle_state' => DomainLifecycleState::Deprovisioning]),
                'disabled_user' => $user->update(['disabled_at' => now()]),
                'unassigned_user' => $domain->users()->detach(),
                'cancelled_operation' => $operation->update(['status' => 'failed']),
            };
            $state = $domain->lifecycle_state;
            $resolver = new class extends NameserverResolver
            {
                public function resolve(string $domain): array
                {
                    return Domain::query()->where('name', $domain)->firstOrFail()->assignedNameservers();
                }
            };
            try {
                (new VerifyDomainNameservers($domain->id))->handle($resolver);
                $this->fail("Verification unexpectedly succeeded: {$scenario}");
            } catch (\RuntimeException|AuthorizationException) {
            }
            $this->assertNull($domain->refresh()->nameservers_verified_at, $scenario);
            $this->assertSame($state, $domain->lifecycle_state, $scenario);
            $this->assertSame(1, $domain->revision, $scenario);
        }
    }

    public function test_stale_shared_delegation_never_activates_a_new_applicant(): void
    {
        Queue::fake();
        PlatformDnsSetting::query()->create([
            'id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']],
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test',
            'soa_refresh' => 3600, 'soa_retry' => 600, 'soa_expire' => 604800,
            'soa_minimum_ttl' => 300, 'default_ttl' => 300, 'cluster_targets' => [],
        ]);
        $user = User::factory()->create();
        $domain = Domain::createPendingFor($user, 'stale.example.com');
        $resolver = new class extends NameserverResolver
        {
            public function resolve(string $domain): array
            {
                return ['ns1.cdnf.test', 'ns2.cdnf.test'];
            }
        };
        try {
            (new VerifyDomainNameservers($domain->id))->handle($resolver);
            $this->fail('Shared stale delegation cannot prove applicant control.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('assigned to this claim', $exception->getMessage());
        }
        $this->assertNull($domain->refresh()->nameservers_verified_at);
        $this->assertSame(32, strlen($domain->delegation_token));
        $this->assertCount(2, $domain->assignedNameservers());
    }

    public function test_expired_claims_are_bounded_and_verified_ownership_is_preserved(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $pending = Domain::createPendingFor($user, 'pending.example.com');
        $verified = Domain::createPendingFor($user, 'verified.example.com');
        $verified->update(['nameservers_verified_at' => now(), 'lifecycle_state' => DomainLifecycleState::Active]);
        $this->travel(8)->days();
        $this->artisan('cdnf:domains:expire-claims')->assertSuccessful();
        $this->assertSame(DomainLifecycleState::Deprovisioning, $pending->refresh()->lifecycle_state);
        $this->assertSame(DomainLifecycleState::Active, $verified->refresh()->lifecycle_state);
        $this->assertSame([$user->id], $verified->users()->pluck('users.id')->all());
        $this->artisan('cdnf:domains:expire-claims')->assertSuccessful();
        $this->assertSame(1, Operation::query()->where('type', 'domain.deprovision')->count());
    }

    public function test_pending_child_does_not_publish_a_zone_over_its_parent(): void
    {
        Queue::fake();
        $parentOwner = User::factory()->create();
        $applicant = User::factory()->create();
        Domain::createPendingFor($parentOwner, 'example.com');
        $child = Domain::createPendingFor($applicant, 'child.example.com');
        Http::preventStrayRequests();
        (new ReconcileDnsZone($child->id))->handle(app(PowerDnsClient::class));
        $this->assertDatabaseCount('dns_deployments', 0);
        $this->assertSame(DomainLifecycleState::PendingVerification, $child->refresh()->lifecycle_state);
    }

    public function test_management_zone_and_its_parent_cannot_be_claimed(): void
    {
        Queue::fake();
        config(['app.url' => 'https://control.ops.example.net']);
        $this->actingAs(User::factory()->create());
        foreach (['ops.example.net', 'example.net', 'edge-control.ops.example.net'] as $name) {
            $this->postJson('/api/domains', ['name' => $name])->assertUnprocessable();
        }
        $this->assertDatabaseCount('domains', 0);
    }

    public function test_disabling_an_unverified_claim_does_not_prevent_expiration(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $domain = Domain::createPendingFor($user, 'disabled.example.com');
        $domain->update(['lifecycle_state' => DomainLifecycleState::Disabled]);
        $this->travel(8)->days();
        $this->artisan('cdnf:domains:expire-claims')->assertSuccessful();
        $this->assertSame(DomainLifecycleState::Deprovisioning, $domain->refresh()->lifecycle_state);
    }

    public function test_pending_claim_limit_is_enforced_without_affecting_other_users(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        foreach (range(1, 20) as $index) {
            Domain::createPendingFor($user, "claim-{$index}.example.com");
        }
        $this->actingAs($user)->postJson('/api/domains', ['name' => 'over-budget.example.com'])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson('/api/domains', ['name' => 'other-user.example.com'])->assertCreated();
        $this->assertDatabaseCount('domains', 21);
    }

    public function test_forced_verification_requires_current_administrator_even_at_internal_boundary(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.com', 'display_name' => 'example.com']);
        $domain->users()->attach($user);
        $this->expectException(\RuntimeException::class);
        app(DomainNameserverVerification::class)->complete($domain, $user, forced: true);
    }
}
