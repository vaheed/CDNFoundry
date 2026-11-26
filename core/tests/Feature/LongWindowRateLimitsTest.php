<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\Edge;
use App\Models\RateMitigation;
use App\Models\RateWindowReport;
use App\Models\SecurityRule;
use App\Support\LongWindowRateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LongWindowRateLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_counts_of_several_cells_are_added_and_a_key_at_the_threshold_is_mitigated_for_the_rule_duration(): void
    {
        [$first, $second, $domain, $rule] = $this->served(threshold: 100);
        $window = intdiv(time(), 3600);
        LongWindowRateLimits::record($first->id, 'cell-01', [['domain_id' => $domain->id, 'rule_id' => $rule->id, 'hash' => self::HASH, 'window' => $window, 'count' => 40]]);
        $this->assertSame(0, LongWindowRateLimits::aggregate(), 'a key under its threshold is not mitigated');

        LongWindowRateLimits::record($second->id, 'cell-01', [['domain_id' => $domain->id, 'rule_id' => $rule->id, 'hash' => self::HASH, 'window' => $window, 'count' => 60]]);
        $this->assertSame(1, LongWindowRateLimits::aggregate());
        $this->assertSame(0, LongWindowRateLimits::aggregate(), 'a key already mitigated is not mitigated again');

        $mitigation = RateMitigation::query()->firstOrFail();
        $this->assertEqualsWithDelta(now()->addSeconds(1800)->timestamp, $mitigation->expires_at->timestamp, 5);
        $delivery = LongWindowRateLimits::delivery($first->id);
        $this->assertSame($mitigation->revision, $delivery['version']);
        $this->assertSame([['domain_id' => $domain->id, 'rule_id' => $rule->id, 'hash' => self::HASH, 'seconds' => $delivery['items'][0]['seconds']]], $delivery['items']);
    }

    public function test_a_cells_newer_report_replaces_its_older_one_and_other_windows_are_not_added(): void
    {
        [$first, , $domain, $rule] = $this->served(threshold: 100);
        $window = intdiv(time(), 3600);
        $entry = ['domain_id' => $domain->id, 'rule_id' => $rule->id, 'hash' => self::HASH, 'window' => $window];
        LongWindowRateLimits::record($first->id, 'cell-01', [$entry + ['count' => 70]]);
        LongWindowRateLimits::record($first->id, 'cell-01', [$entry + ['count' => 80]]);
        LongWindowRateLimits::record($first->id, 'cell-01', [array_merge($entry, ['window' => $window - 1, 'count' => 90])]);

        $this->assertSame(2, RateWindowReport::query()->count());
        $this->assertSame(0, LongWindowRateLimits::aggregate(), '80 in this window and 90 in the last one never add up');
    }

    public function test_reports_for_a_rule_of_another_domain_or_a_bad_hash_are_dropped(): void
    {
        [$first, , $domain, $rule] = $this->served(threshold: 100);
        $other = Domain::query()->create(['name' => 'other.test', 'display_name' => 'Other', 'lifecycle_state' => 'active', 'revision' => 1]);

        $stored = LongWindowRateLimits::record($first->id, 'cell-01', [
            ['domain_id' => $other->id, 'rule_id' => $rule->id, 'hash' => self::HASH, 'window' => 1, 'count' => 5],
            ['domain_id' => $domain->id, 'rule_id' => $rule->id, 'hash' => 'not-a-hash', 'window' => 1, 'count' => 5],
            ['domain_id' => $domain->id, 'rule_id' => $rule->id, 'hash' => self::HASH, 'window' => 1, 'count' => 0],
        ]);

        $this->assertSame(0, $stored);
        $this->assertSame(0, RateWindowReport::query()->count());
    }

    public function test_a_per_cell_or_short_window_rule_is_never_aggregated_and_a_domain_holds_a_bounded_number(): void
    {
        [$first, , $domain, $rule] = $this->served(threshold: 10);
        $window = intdiv(time(), 3600);
        $short = $domain->securityRules()->create(['action' => 'block', 'priority' => 2, 'conditions' => [], 'fingerprint' => 's', 'rate_limit' => ['key' => 'client_ip', 'threshold' => 1, 'period_seconds' => 60, 'duration_seconds' => 60, 'scope' => 'shared']]);
        $perCell = $domain->securityRules()->create(['action' => 'block', 'priority' => 3, 'conditions' => [], 'fingerprint' => 'p', 'rate_limit' => ['key' => 'client_ip', 'threshold' => 1, 'period_seconds' => 3600, 'duration_seconds' => 60, 'scope' => 'per_cell']]);
        foreach ([$short, $perCell] as $unfit) {
            LongWindowRateLimits::record($first->id, 'cell-01', [['domain_id' => $domain->id, 'rule_id' => $unfit->id, 'hash' => self::HASH, 'window' => $window, 'count' => 999]]);
        }
        $this->assertSame(0, LongWindowRateLimits::aggregate());

        for ($index = 0; $index < LongWindowRateLimits::MAXIMUM_PER_DOMAIN + 20; $index++) {
            RateWindowReport::query()->create(['edge_id' => $first->id, 'cell_name' => 'cell-01', 'domain_id' => $domain->id, 'rule_id' => $rule->id,
                'key_hash' => md5((string) $index), 'window' => $window, 'count' => 50, 'reported_at' => now()]);
        }
        LongWindowRateLimits::aggregate();
        $this->assertSame(LongWindowRateLimits::MAXIMUM_PER_DOMAIN, RateMitigation::query()->count());
    }

    public function test_an_edge_is_given_only_the_mitigations_of_domains_it_serves_and_expired_ones_are_left_out(): void
    {
        [$first, , $domain, $rule] = $this->served(threshold: 10);
        $stranger = Edge::query()->create(['name' => 'stranger', 'country_code' => 'DE', 'continent_code' => 'EU']);
        RateMitigation::query()->create(['domain_id' => $domain->id, 'rule_id' => $rule->id, 'key_hash' => self::HASH, 'revision' => 1, 'expires_at' => now()->addMinutes(5)]);
        RateMitigation::query()->create(['domain_id' => $domain->id, 'rule_id' => $rule->id, 'key_hash' => str_repeat('b', 32), 'revision' => 2, 'expires_at' => now()->subMinute()]);

        $this->assertCount(1, LongWindowRateLimits::delivery($first->id)['items']);
        $this->assertSame([], LongWindowRateLimits::delivery($stranger->id)['items']);
    }

    public function test_heartbeats_of_two_edges_lead_to_a_mitigation_every_serving_edge_is_given(): void
    {
        [$first, $second, $domain, $rule] = $this->served(threshold: 100);
        $window = intdiv(time(), 3600);
        foreach ([[$first, 55], [$second, 50]] as [$edge, $count]) {
            $edge->cells()->update(['name' => 'cell-01']);
            $request = Request::create('/edge/v1/heartbeat', 'POST', [
                'agent_version' => '1.2.0', 'listener_ready' => true, 'active_sequence' => 0,
                'cells' => [['name' => 'cell-01', 'status' => 'ready', 'capacity' => ['active_connections' => 0]]],
                'long_rate' => [['cell' => 'cell-01', 'domain_id' => $domain->id, 'rule_id' => $rule->id, 'hash' => self::HASH, 'window' => $window, 'count' => $count],
                    ['cell' => 'cell-09', 'domain_id' => $domain->id, 'rule_id' => $rule->id, 'hash' => self::HASH, 'window' => $window, 'count' => 999], 'junk'],
            ]);
            $request->attributes->set('edge', $edge);
            $this->assertSame(200, app(EdgeAgentController::class)->heartbeat($request)->getStatusCode());
        }

        $this->assertSame(2, RateWindowReport::query()->count(), 'a cell the edge does not have and a malformed entry are dropped');
        $this->artisan('cdnf:rate-limits:aggregate')->expectsOutput('mitigations_added=1')->assertSuccessful();
        foreach ([$first, $second] as $edge) {
            $items = LongWindowRateLimits::delivery($edge->id)['items'];
            $this->assertSame([$domain->id], array_column($items, 'domain_id'));
        }
    }

    /** @return array{0: Edge, 1: Edge, 2: Domain, 3: SecurityRule} */
    private function served(int $threshold): array
    {
        $domain = Domain::query()->create(['name' => 'long.test', 'display_name' => 'Long', 'lifecycle_state' => 'active', 'revision' => 1]);
        $edges = [];
        foreach (['first', 'second'] as $name) {
            $edge = Edge::query()->create(['name' => $name, 'country_code' => 'DE', 'continent_code' => 'EU']);
            $cell = $edge->cells()->create(['slot' => 1, 'status' => 'ready']);
            DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => 1, 'active_cell_id' => $cell->id, 'desired_revision' => 1, 'state' => 'active']);
            $edges[] = $edge;
        }
        $rule = $domain->securityRules()->create(['action' => 'block', 'priority' => 1, 'conditions' => [], 'fingerprint' => 'long',
            'rate_limit' => ['key' => 'client_ip', 'threshold' => $threshold, 'period_seconds' => 3600, 'duration_seconds' => 1800, 'scope' => 'shared', 'window' => 'fixed']]);

        return [$edges[0], $edges[1], $domain, $rule];
    }
}
