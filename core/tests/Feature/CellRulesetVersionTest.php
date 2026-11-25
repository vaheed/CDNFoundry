<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Support\RulesetPins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CellRulesetVersionTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(Edge $edge, array $capacity): void
    {
        $request = Request::create('/edge/v1/heartbeat', 'POST', [
            'agent_version' => '1.2.0', 'listener_ready' => true, 'active_sequence' => 0,
            'cells' => [['name' => 'cell-01', 'status' => 'ready', 'capacity' => $capacity]],
        ]);
        $request->attributes->set('edge', $edge);
        $this->assertSame(200, app(EdgeAgentController::class)->heartbeat($request)->getStatusCode());
    }

    private function edge(): Edge
    {
        $edge = Edge::query()->create([
            'name' => 'ruleset-pop', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true,
            'registered_at' => now(), 'last_heartbeat_at' => now(), 'active_sequence' => 0, 'capacity' => ['listener_ready' => true],
        ]);
        $pool = EdgePool::query()->create(['name' => 'ruleset-pool']);
        $edge->cells()->create(['slot' => 1, 'status' => 'ready', 'edge_pool_id' => $pool->id]);

        return $edge;
    }

    private function scrape(): string
    {
        config(['services.metrics.token' => 'metrics-test-token']);
        $this->app['auth']->forgetGuards();

        return $this->withToken('metrics-test-token')->get('/metrics')->assertOk()->getContent();
    }

    public function test_the_state_is_pinned_only_for_both_exact_commits(): void
    {
        $pins = RulesetPins::pinned();

        $this->assertSame(RulesetPins::MATCHING, RulesetPins::state($pins));
        $this->assertSame(RulesetPins::DIFFERENT, RulesetPins::state([...$pins, 'crs' => str_repeat('a', 40)]));
        $this->assertSame(RulesetPins::DIFFERENT, RulesetPins::state([...$pins, 'modsecurity' => str_repeat('b', 40)]));
        $this->assertSame(RulesetPins::UNREPORTED, RulesetPins::state(null));
        $this->assertSame(RulesetPins::UNREPORTED, RulesetPins::state(['crs' => null, 'modsecurity' => null]));
    }

    public function test_a_cell_report_is_stored_and_a_mismatch_or_missing_report_is_exported_as_one(): void
    {
        Queue::fake();
        $edge = $this->edge();

        $this->heartbeat($edge, ['active_connections' => 0, 'ruleset_versions' => RulesetPins::pinned()]);
        $this->assertSame(RulesetPins::pinned(), $edge->cells()->first()->capacity['ruleset_versions']);
        $this->assertMatchesRegularExpression('/cdnfoundry_cell_ruleset_mismatch\{[^}]*cell="cell-01"\} 0\n/', $this->scrape());

        $this->heartbeat($edge, ['active_connections' => 0, 'ruleset_versions' => [...RulesetPins::pinned(), 'crs' => str_repeat('c', 40)]]);
        $this->assertMatchesRegularExpression('/cdnfoundry_cell_ruleset_mismatch\{[^}]*cell="cell-01"\} 1\n/', $this->scrape());

        $this->heartbeat($edge, ['active_connections' => 0]);
        $this->assertMatchesRegularExpression('/cdnfoundry_cell_ruleset_mismatch\{[^}]*cell="cell-01"\} 1\n/', $this->scrape());
    }

    public function test_a_malformed_commit_is_refused_and_the_previous_report_stays(): void
    {
        Queue::fake();
        $edge = $this->edge();
        $this->heartbeat($edge, ['active_connections' => 0, 'ruleset_versions' => RulesetPins::pinned()]);

        try {
            $this->heartbeat($edge, ['active_connections' => 0, 'ruleset_versions' => ['crs' => 'main; DROP', 'modsecurity' => null]]);
            $this->fail('A commit that is not 40 hexadecimal characters must be refused.');
        } catch (ValidationException) {
            $this->assertSame(RulesetPins::pinned(), $edge->cells()->first()->capacity['ruleset_versions']);
        }
    }

    public function test_a_pool_is_pinned_only_when_every_running_cell_is_and_one_different_cell_makes_it_different(): void
    {
        $pool = EdgePool::query()->create(['name' => 'summary-pool']);
        $edge = Edge::query()->create(['name' => 'summary-pop', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now(), 'active_sequence' => 0, 'capacity' => []]);
        $cell = fn (int $slot, string $status, ?array $versions) => $edge->cells()->create(['slot' => $slot, 'status' => $status, 'edge_pool_id' => $pool->id, 'capacity' => $versions === null ? [] : ['ruleset_versions' => $versions]]);
        $summary = fn () => RulesetPins::summary($pool->fresh()->cells);

        $this->assertSame(RulesetPins::NO_CELLS, $summary()['state']);

        $cell(1, 'ready', RulesetPins::pinned());
        $this->assertSame(RulesetPins::MATCHING, $summary()['state']);

        $cell(2, 'ready', null);
        $this->assertSame(RulesetPins::UNREPORTED, $summary()['state']);

        $cell(3, 'ready', [...RulesetPins::pinned(), 'crs' => str_repeat('d', 40)]);
        $this->assertSame(RulesetPins::DIFFERENT, $summary()['state']);
        $this->assertSame([RulesetPins::MATCHING => 1, RulesetPins::DIFFERENT => 1, RulesetPins::UNREPORTED => 1], $summary()['counts']);

        $pool->cells()->where('slot', 3)->update(['status' => 'stopped']);
        $this->assertSame(RulesetPins::UNREPORTED, $summary()['state'], 'a stopped cell serves nothing and is not counted');
    }
}
