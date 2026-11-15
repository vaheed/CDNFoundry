<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Models\Edge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeConnectionsStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_status_shows_how_many_connections_are_open_and_how_many_the_gateway_has_served(): void
    {
        $admin = User::factory()->admin()->create();
        $edge = Edge::query()->create([
            'name' => 'live-pop', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now(),
            'capacity' => [
                'listener_ready' => true,
                'cells' => [['name' => 'cell-01', 'status' => 'ready', 'capacity' => ['active_connections' => 7]], ['name' => 'cell-02', 'status' => 'ready', 'capacity' => ['active_connections' => 5]]],
                'gateway' => ['ready' => true, 'connections_active' => 0, 'connections_accepted' => 48213],
            ],
        ]);

        $this->actingAs($admin)->get(EdgeResource::getUrl('view', ['record' => $edge], panel: 'admin'))->assertOk()
            ->assertSee('Active connections')->assertSee('12')
            ->assertSee('Gateway connections served')->assertSee('48,213');
    }
}
