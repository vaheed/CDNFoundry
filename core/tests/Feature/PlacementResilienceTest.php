<?php

namespace Tests\Feature;

use App\Actions\PromoteReadyEdgePlacements;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgePool;
use App\Models\EdgeRevision;
use App\Models\PlatformDnsSetting;
use App\Support\OriginPolicy;
use App\Support\PlatformSettings;
use App\Support\PowerDnsZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlacementResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_a_lagging_pop_does_not_block_promotion_once_the_grace_window_passes(): void
    {
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $fast = $this->readyEdge($pool, 'fast');
        $slow = $this->readyEdge($pool, 'slow');
        $placement = $this->deployingPlacement('lag.example', $pool, [$fast, $slow]);
        $this->acknowledge($placement, $fast);

        PromoteReadyEdgePlacements::execute();
        $this->assertSame('deploying', $placement->fresh()->state, 'a healthy PoP that has not yet acknowledged still counts');

        DomainEdgePlacement::query()->whereKey($placement->id)->update(['updated_at' => now()->subMinutes(5)]);
        PromoteReadyEdgePlacements::execute();
        $this->assertSame('active', $placement->fresh()->state);
        $this->assertSame($placement->desired_revision, Domain::query()->findOrFail($placement->domain_id)->active_edge_revision);
    }

    public function test_promotion_needs_at_least_one_acknowledgement_even_after_the_grace_window(): void
    {
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $slow = $this->readyEdge($pool, 'only');
        $placement = $this->deployingPlacement('none.example', $pool, [$slow]);
        DomainEdgePlacement::query()->whereKey($placement->id)->update(['updated_at' => now()->subHour()]);

        PromoteReadyEdgePlacements::execute();

        $this->assertSame('deploying', $placement->fresh()->state);
    }

    public function test_the_scheduled_walk_reaches_placements_beyond_the_first_page(): void
    {
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $edge = $this->readyEdge($pool, 'solo');
        $stuck = [];
        for ($index = 0; $index < 3; $index++) {
            $stuck[] = $this->deployingPlacement("stuck-{$index}.example", $pool, [$edge]);
        }
        $ready = $this->deployingPlacement('after-the-page.example', $pool, [$edge]);
        $this->acknowledge($ready, $edge);

        PromoteReadyEdgePlacements::execute(2, 1);
        $this->assertSame('deploying', $ready->fresh()->state, 'one page of two stuck rows hides the later row');

        $this->artisan('cdnf:edge:promote-placements', ['--chunk' => 2, '--chunks' => 10])->assertSuccessful();
        $this->assertSame('active', $ready->fresh()->state);
    }

    public function test_automatic_placement_skips_withdrawn_pools(): void
    {
        $withdrawn = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $other = EdgePool::query()->create(['name' => 'second-shared', 'kind' => 'shared', 'enabled' => true]);
        $withdrawn->update(['withdrawn' => true]);
        $domain = Domain::query()->create(['name' => 'skip-withdrawn.example', 'display_name' => 'Skip', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->dnsRecords()->create([
            'type' => 'A', 'name' => 'www.skip-withdrawn.example', 'content' => '8.8.8.8', 'ttl' => 60, 'mode' => 'proxied',
            'content_hash' => hash('sha256', '8.8.8.8'),
            'origin' => ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 1],
        ]);

        (new ReconcileEdgeDomain($domain->id))->handle();

        $this->assertSame($other->id, DomainEdgePlacement::query()->where('domain_id', $domain->id)->value('target_pool_id'));
    }

    public function test_automatic_placement_never_selects_a_non_shared_pool(): void
    {
        foreach (['reserved', 'dedicated'] as $kind) {
            EdgePool::query()->create(['name' => "extra-{$kind}", 'kind' => $kind, 'enabled' => true]);
        }
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $this->assertSame(1, EdgePool::query()->where('kind', 'quarantine')->count());
        $origin = ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 1];
        for ($index = 0; $index < 40; $index++) {
            $domain = Domain::query()->create(['name' => "spread-{$index}.example", 'display_name' => 'Spread', 'revision' => 1, 'lifecycle_state' => 'active']);
            $domain->dnsRecords()->create(['type' => 'A', 'name' => "spread-{$index}.example", 'content' => '8.8.8.8', 'ttl' => 60, 'mode' => 'proxied', 'content_hash' => 'h', 'origin' => $origin]);
            (new ReconcileEdgeDomain($domain->id))->handle();
        }

        $this->assertSame(40, DomainEdgePlacement::query()->where('target_pool_id', $shared->id)->count());
        $this->assertSame(0, DomainEdgePlacement::query()->where('target_pool_id', '!=', $shared->id)->count());
    }

    public function test_the_origin_policy_is_sorted_and_a_policy_change_does_not_change_any_domain_snapshot(): void
    {
        app(PlatformSettings::class)->update('origin_safety', [
            'blocked_origin_addresses' => ['9.9.9.9', '2001:db8::5', '1.1.1.1'],
            'blocked_origin_networks' => ['192.0.2.0/24', '100.64.0.0/10'],
            'private_origin_allowlist' => ['10.2.0.0/16', '10.1.0.0/16'],
        ]);
        $policy = OriginPolicy::compile();
        $this->assertSame(['1.1.1.1', '2001:db8::5', '9.9.9.9'], array_values(array_intersect($policy['blocked_addresses'], ['9.9.9.9', '2001:db8::5', '1.1.1.1'])));
        $this->assertSame(['100.64.0.0/10', '192.0.2.0/24'], $policy['blocked_networks']);
        $this->assertSame(['10.1.0.0/16', '10.2.0.0/16'], $policy['private_allowlist']);

        $domain = Domain::query()->create(['name' => 'ordered.example', 'display_name' => 'Ordered', 'revision' => 1, 'lifecycle_state' => 'active']);
        $origin = ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 1];
        $domain->dnsRecords()->create(['type' => 'A', 'name' => 'ordered.example', 'content' => '8.8.8.8', 'ttl' => 60, 'mode' => 'proxied', 'content_hash' => 'a', 'origin' => $origin]);
        (new ReconcileEdgeDomain($domain->id))->handle();
        $first = EdgeRevision::query()->where('domain_id', $domain->id)->firstOrFail();

        // A pool address change alters the policy but must not touch, recompile or re-sign a domain.
        EdgePool::query()->where('kind', 'shared')->update(['anycast_ipv4' => '198.51.100.77']);
        $this->assertContains('198.51.100.77', OriginPolicy::compile()['blocked_addresses']);
        (new ReconcileEdgeDomain($domain->id))->handle();

        $this->assertSame(1, EdgeRevision::query()->where('domain_id', $domain->id)->count());
        $this->assertSame($first->checksum, EdgeRevision::query()->where('domain_id', $domain->id)->value('checksum'));
    }

    public function test_the_zone_publishes_proxied_names_before_any_placement_is_active(): void
    {
        $this->platformSettings();
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $domain = Domain::query()->create(['name' => 'onboarding.example', 'display_name' => 'Onboarding', 'revision' => 1, 'lifecycle_state' => 'active']);
        $origin = ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 1];
        $domain->dnsRecords()->create(['type' => 'A', 'name' => 'onboarding.example', 'content' => '8.8.8.8', 'ttl' => 60, 'mode' => 'proxied', 'content_hash' => 'a', 'origin' => $origin]);
        $domain->dnsRecords()->create(['type' => 'A', 'name' => 'www.onboarding.example', 'content' => '8.8.8.8', 'ttl' => 60, 'mode' => 'proxied', 'content_hash' => 'b', 'origin' => $origin]);
        $domain->dnsRecords()->create(['type' => 'MX', 'name' => 'onboarding.example', 'content' => 'mail.onboarding.example', 'priority' => 10, 'ttl' => 60, 'mode' => 'dns_only', 'content_hash' => 'c']);
        DomainEdgePlacement::query()->create(['domain_id' => $domain->id, 'target_pool_id' => $pool->id, 'desired_revision' => 1, 'state' => 'deploying']);

        $rrsets = collect(PowerDnsZone::render($domain));

        $this->assertNotNull($rrsets->firstWhere(fn (array $set): bool => $set['type'] === 'MX'), 'MX resolves immediately');
        $cname = $rrsets->firstWhere(fn (array $set): bool => $set['type'] === 'CNAME' && $set['name'] === 'www.onboarding.example.');
        $this->assertStringStartsWith('pool-'.$pool->id.'.', $cname['records'][0]['content']);
        $this->assertNotNull($rrsets->firstWhere(fn (array $set): bool => $set['type'] === 'LUA'));
    }

    private function platformSettings(): void
    {
        PlatformDnsSetting::query()->updateOrCreate(['id' => 1], [
            'platform_domain' => 'platform.test', 'proxy_hostname' => 'edge.platform.test', 'soa_primary' => 'ns1.platform.test',
            'soa_mailbox' => 'hostmaster.platform.test', 'default_ttl' => 60, 'soa_refresh' => 3600, 'soa_retry' => 600,
            'soa_expire' => 86400, 'soa_minimum_ttl' => 60, 'nameservers' => [['hostname' => 'ns1.platform.test', 'ipv4' => '203.0.113.1', 'ipv6' => null]],
            'cluster_targets' => [], 'revision' => 1,
        ]);
    }

    private function readyEdge(EdgePool $pool, string $name): Edge
    {
        $edge = Edge::query()->create([
            'name' => $name, 'country_code' => 'DE', 'continent_code' => 'EU', 'management_ipv4' => '203.0.113.'.random_int(10, 250),
            'cell_slot_count' => 8, 'enabled' => true, 'drained' => false, 'registered_at' => now(), 'last_heartbeat_at' => now(),
            'capacity' => ['listener_ready' => true], 'active_sequence' => 0,
        ]);
        for ($slot = 1; $slot <= 3; $slot++) {
            $edge->cells()->create(['slot' => $slot, 'name' => 'cell-0'.$slot, 'edge_pool_id' => $pool->id, 'status' => 'ready']);
        }

        return $edge;
    }

    /** @param list<Edge> $edges */
    private function deployingPlacement(string $name, EdgePool $pool, array $edges): DomainEdgePlacement
    {
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'revision' => 3, 'lifecycle_state' => 'active']);
        $placement = DomainEdgePlacement::query()->create([
            'domain_id' => $domain->id, 'target_pool_id' => $pool->id, 'desired_revision' => 3, 'state' => 'deploying',
        ]);
        foreach ($edges as $edge) {
            DomainEdgeCell::query()->create([
                'domain_id' => $domain->id, 'edge_id' => $edge->id, 'target_cell_id' => $edge->cells()->first()->id,
                'desired_revision' => 3, 'state' => 'deploying',
            ]);
        }

        return $placement;
    }

    private function acknowledge(DomainEdgePlacement $placement, Edge $edge): void
    {
        $sequence = (int) EdgeArtifact::query()->max('sequence') + 1;
        EdgeArtifact::query()->create([
            'edge_id' => $edge->id, 'domain_id' => $placement->domain_id, 'revision' => $placement->desired_revision, 'sequence' => $sequence,
            'kind' => 'domain', 'checksum' => hash('sha256', Str::random(8)), 'signature' => 'aa', 'payload' => [],
        ]);
        $edge->update(['active_sequence' => $sequence]);
    }
}
