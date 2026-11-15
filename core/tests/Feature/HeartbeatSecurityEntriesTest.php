<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\Domain;
use App\Models\Edge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class HeartbeatSecurityEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_heartbeat_with_more_security_entries_than_the_limit_is_accepted_and_the_excess_is_counted(): void
    {
        $edge = Edge::query()->create(['name' => 'busy-pop', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now(), 'active_sequence' => 0, 'capacity' => ['listener_ready' => true]]);
        $edge->cells()->create(['slot' => 1, 'status' => 'unassigned']);
        $domain = Domain::query()->create(['name' => 'busy.test', 'display_name' => 'Busy', 'lifecycle_state' => 'active', 'revision' => 1]);
        $entries = array_map(fn (int $index): array => [
            'domain_id' => $domain->id + $index, 'hostname' => 'busy.test', 'reason_code' => 'client_rate_exceeded', 'count' => 3, 'occurred_at' => now()->timestamp, 'clients' => 2,
        ], range(0, EdgeAgentController::MAXIMUM_SECURITY_ENTRIES + 49));
        $request = Request::create('/edge/v1/heartbeat', 'POST', [
            'agent_version' => '1.2.0', 'listener_ready' => false, 'active_sequence' => 0,
            'cells' => [['name' => 'cell-01', 'status' => 'stopped', 'capacity' => ['active_connections' => 0]]],
            'noisy_domains' => $entries,
        ]);
        $request->attributes->set('edge', $edge);

        $this->assertSame(200, app(EdgeAgentController::class)->heartbeat($request)->getStatusCode(), 'a heartbeat is never failed for carrying too many entries');

        $edge->refresh();
        $this->assertCount(EdgeAgentController::MAXIMUM_SECURITY_ENTRIES, $edge->capacity['noisy_domains']);
        $this->assertSame(50, $edge->capacity['advisory_entries_dropped']);
    }
}
