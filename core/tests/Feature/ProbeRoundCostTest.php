<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use App\Models\EdgeProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every edge probes the listeners of the others and reports the round. The control plane's cost of one report must not grow with the
 * number of edges once nothing changed, because each of N edges reports every few seconds (N reports for N edges, N squared results).
 */
class ProbeRoundCostTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{edges: list<Edge>, results: list<array<string, mixed>>} */
    private function fleet(int $count): array
    {
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $edges = [];
        foreach (range(1, $count) as $index) {
            $edge = Edge::query()->create(['name' => "pop-$index", 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => true, 'drained' => false,
                'registered_at' => now(), 'last_heartbeat_at' => now(), 'capacity' => ['listener_ready' => true]]);
            $edge->cells()->create(['slot' => 1, 'name' => 'cell-01', 'edge_pool_id' => $pool->id, 'status' => 'ready']);
            $pool->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => '198.51.'.intdiv($index, 250).'.'.(($index % 250) + 1), 'gateway_state' => 'ready', 'revision' => 1, 'gateway_revision' => 1]);
            $edges[] = $edge;
        }

        return ['edges' => $edges, 'results' => []];
    }

    /** @return array{queries: int, ms: float} the cost of one report from the first edge */
    private function report(array $edges, bool $ok = true): array
    {
        $observer = $edges[0];
        $results = collect($edges)->slice(1)->map(function (Edge $target): array {
            $endpoint = EdgePoolEndpoint::query()->where('edge_id', $target->id)->firstOrFail();

            return ['edge_id' => $target->id, 'address' => $endpoint->ipv4, 'ok' => true];
        })->take(128)->values()->all();
        if (! $ok) {
            $results[0]['ok'] = false;
        }
        $request = Request::create('/edge/v1/probes', 'POST', ['results' => $results]);
        $request->attributes->set('edge', $observer);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = microtime(true);
        app(EdgeAgentController::class)->probeResults($request);
        $cost = ['queries' => count(DB::getQueryLog()), 'ms' => round(1000 * (microtime(true) - $started), 1)];
        DB::disableQueryLog();

        return $cost;
    }

    public function test_the_cost_of_an_unchanged_probe_round_does_not_grow_with_the_number_of_edges(): void
    {
        Queue::fake();
        $costs = [];
        foreach ([10, 80, 100] as $size) {
            DB::table('edge_probe_results')->delete();
            DB::table('edge_pool_endpoints')->delete();
            Edge::query()->delete();
            $fleet = $this->fleet($size);
            $this->report($fleet['edges']);
            $costs[$size] = $this->report($fleet['edges']);
        }
        fwrite(STDERR, "\nunchanged probe round (queries, ms): ".json_encode($costs)."\n");
        $this->assertLessThanOrEqual(12, $costs[100]['queries'], 'an unchanged round of 99 results is a handful of queries');
        $this->assertLessThanOrEqual($costs[10]['queries'] + 3, $costs[100]['queries'], 'the number of queries does not follow the number of edges');
    }

    public function test_a_changed_result_is_still_recorded_and_an_unchanged_one_is_refreshed_only_when_stale(): void
    {
        Queue::fake();
        $fleet = $this->fleet(6);
        $this->report($fleet['edges']);
        $this->assertSame(5, EdgeProbeResult::query()->count());
        $checked = EdgeProbeResult::query()->pluck('checked_at', 'id')->map(fn ($time) => $time->getTimestamp())->all();
        $this->travel(10)->seconds();
        $this->report($fleet['edges']);
        $this->assertSame($checked, EdgeProbeResult::query()->pluck('checked_at', 'id')->map(fn ($time) => $time->getTimestamp())->all(), 'an unchanged result is not rewritten every round');
        $this->report($fleet['edges'], ok: false);
        $this->assertSame(1, EdgeProbeResult::query()->where('ok', false)->count(), 'a change is recorded at once');
        $this->travel(120)->seconds();
        $this->report($fleet['edges'], ok: false);
        $refreshed = EdgeProbeResult::query()->where('ok', true)->get()->map(fn ($row) => $row->checked_at->getTimestamp())->min();
        $this->assertGreaterThan(max($checked), $refreshed, 'a stale result is refreshed so its age stays honest');
    }
}
