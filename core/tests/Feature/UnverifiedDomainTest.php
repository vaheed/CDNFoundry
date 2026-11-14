<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\DnsRecordsRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\SecurityEventsRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\SecurityRulesRelationManager;
use App\Models\Domain;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\PowerDnsZone;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class UnverifiedDomainTest extends TestCase
{
    use RefreshDatabase;

    private function pendingDomain(): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'pending.example', 'display_name' => 'Pending', 'revision' => 1, 'lifecycle_state' => DomainLifecycleState::PendingVerification]);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    public function test_an_unverified_domain_refuses_every_feature_except_dns_records(): void
    {
        [$user, $domain] = $this->pendingDomain();
        $base = "/api/domains/{$domain->id}";

        foreach (['/security', '/security/rules', '/waf', '/tls', '/cache', '/proxy', '/deployment', '/revisions', '/log-push', '/incident', '/analytics/summary', '/logs/requests', '/usage'] as $path) {
            $this->actingAs($user)->getJson($base.$path)->assertStatus(409)->assertJsonPath('code', 'domain_not_verified');
        }
        $this->actingAs($user)->patchJson($base.'/security', ['profile' => 'standard'])->assertStatus(409)->assertJsonPath('code', 'domain_not_verified');
        $this->actingAs($user)->postJson($base.'/cache/purge', ['mode' => 'all'])->assertStatus(409)->assertJsonPath('code', 'domain_not_verified');

        foreach (['', '/status', '/dns/records', '/dns/export', '/dns/deployment'] as $path) {
            $this->actingAs($user)->getJson($base.$path)->assertOk();
        }
    }

    public function test_an_unverified_domain_accepts_dns_only_records_and_refuses_proxied_and_geo_records(): void
    {
        [$user, $domain] = $this->pendingDomain();
        $base = "/api/domains/{$domain->id}/dns/records";

        $this->actingAs($user)->postJson($base, ['type' => 'A', 'name' => '@', 'content' => '192.0.2.1', 'ttl' => 300])->assertCreated();
        $this->actingAs($user)->postJson($base, ['type' => 'A', 'name' => 'www', 'ttl' => 300, 'mode' => 'proxied', 'origin' => ['host' => '198.51.100.10', 'port' => 443, 'scheme' => 'https']])
            ->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->assertDatabaseCount('dns_records', 1);
    }

    public function test_an_unverified_zone_publishes_only_its_delegation_and_a_verified_zone_publishes_the_records(): void
    {
        [$user, $domain] = $this->pendingDomain();
        PlatformDnsSetting::query()->create([
            'id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test', 'ipv4' => '192.0.2.1', 'ipv6' => '2001:db8::1'], ['hostname' => 'ns2.cdnf.test', 'ipv4' => '192.0.2.2', 'ipv6' => '2001:db8::2']],
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test', 'soa_refresh' => 3600,
            'soa_retry' => 600, 'soa_expire' => 604800, 'soa_minimum_ttl' => 300, 'default_ttl' => 300,
        ]);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", ['type' => 'A', 'name' => 'app', 'content' => '192.0.2.7', 'ttl' => 300])->assertCreated();

        $contents = fn (array $rrsets): array => collect($rrsets)->pluck('type')->all();
        $this->assertNotContains('A', $contents(PowerDnsZone::renderDelegation($domain->fresh())));

        $domain->forceFill(['nameservers_verified_at' => now(), 'lifecycle_state' => DomainLifecycleState::Active])->save();
        $this->assertContains('A', $contents(PowerDnsZone::render($domain->fresh())));
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security")->assertOk();
    }

    public function test_the_domain_page_of_an_unverified_domain_shows_only_the_dns_records_tab_and_the_delegation(): void
    {
        [$user, $domain] = $this->pendingDomain();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($user);

        $page = Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()]);
        $managers = array_values($page->instance()->getRelationManagers());
        $this->assertContains(DnsRecordsRelationManager::class, $managers);
        $this->assertNotContains(SecurityRulesRelationManager::class, $managers);
        $this->assertNotContains(SecurityEventsRelationManager::class, $managers);
        $page->assertSee('Delegation')->assertDontSee('Cache')->assertDontSee('Under attack mode')->assertDontSee('Security profile');

        $domain->forceFill(['nameservers_verified_at' => now(), 'lifecycle_state' => DomainLifecycleState::Active])->save();
        $verified = array_values(Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])->instance()->getRelationManagers());
        $this->assertContains(SecurityRulesRelationManager::class, $verified);
    }
}
