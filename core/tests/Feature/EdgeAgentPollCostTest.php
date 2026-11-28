<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\Edge;
use App\Models\EdgePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every edge agent polls the control plane on a timer, so the cost of one poll is paid once per edge per interval. Each read-only
 * poll is run for one edge of a small fleet and of a fleet with 100 edges and 300 domains placed on all of them, and its queries
 * are counted. A poll that grows with the number of domains or edges would multiply by the fleet size.
 */
class EdgeAgentPollCostTest extends TestCase
{
    use RefreshDatabase;

    private const POLLS = ['manifest', 'tasks', 'probeTargets', 'gatewayConfig', 'challengeKeys', 'rateMitigations', 'originPolicy', 'controlAddresses', 'dnsChanges'];

    /** The most queries one idle poll may run, and the most it may add for 300 domains on 100 edges. */
    private const BOUND = 15;

    private const GROWTH = 6;

    private function poll(string $method, Edge $edge): int
    {
        $request = Request::create('/edge/v1/poll', 'GET');
        $request->attributes->set('edge', $edge);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(EdgeAgentController::class)->{$method}($request);
        } catch (\Throwable) {
            // A poll that needs a runtime this test does not have still ran its database work first.
        }
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_agent_polls_do_a_bounded_amount_of_work_that_does_not_grow_with_domains_and_edges(): void
    {
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $first = Edge::query()->create(['name' => 'poll-edge-1', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now()]);
        $first->cells()->create(['slot' => 1, 'name' => 'cell-01', 'edge_pool_id' => $pool->id, 'status' => 'ready']);
        $small = [];
        foreach (self::POLLS as $method) {
            $small[$method] = $this->poll($method, $first);
        }

        $edges = [$first];
        foreach (range(2, 100) as $index) {
            $edge = Edge::query()->create(['name' => "poll-edge-$index", 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now()]);
            $edge->cells()->create(['slot' => 1, 'name' => 'cell-01', 'edge_pool_id' => $pool->id, 'status' => 'ready']);
            $edges[] = $edge;
        }
        foreach (range(1, 300) as $index) {
            $domain = Domain::query()->create(['name' => "poll-$index.example", 'display_name' => "Poll $index", 'lifecycle_state' => 'active', 'revision' => 1, 'nameservers_verified_at' => now()]);
            foreach (array_slice($edges, 0, 3) as $edge) {
                DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'desired_revision' => 1, 'state' => 'active']);
            }
        }
        $large = [];
        foreach (self::POLLS as $method) {
            $large[$method] = $this->poll($method, $first);
        }

        fwrite(STDERR, "\nedge agent poll: queries with 1 edge and no domain -> 100 edges and 300 domains\n");
        foreach (self::POLLS as $method) {
            fwrite(STDERR, sprintf("  %-20s %3d -> %3d\n", $method, $small[$method], $large[$method]));
        }
        foreach (self::POLLS as $method) {
            $this->assertLessThanOrEqual(self::BOUND, $small[$method], "$method runs too many queries when nothing changed");
            $this->assertLessThanOrEqual($small[$method] + self::GROWTH, $large[$method], "$method grows with the number of domains and edges");
        }
    }
}
