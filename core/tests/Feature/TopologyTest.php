<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Topology;
use App\Models\DnsCluster;
use App\Models\Edge;
use App\Models\User;
use App\Support\TopologyGraph;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TopologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_read_the_topology_and_the_payload_is_explicit(): void
    {
        $this->getJson('/api/admin/system/topology')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/system/topology')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/system/topology')->assertOk()
            ->assertJsonStructure(['data' => ['nodes' => [['id', 'kind', 'label', 'status', 'detail']], 'links', 'truncated']])
            ->assertJsonPath('data.nodes.0.id', 'control:self');
    }

    public function test_a_stale_pop_is_failed_and_its_link_takes_the_worse_status(): void
    {
        $fresh = Edge::query()->create(['name' => 'pop-fresh', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.1', 'last_heartbeat_at' => now()]);
        $stale = Edge::query()->create(['name' => 'pop-stopped', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.2', 'last_heartbeat_at' => now()->subDay()]);
        $stale->cells()->create(['slot' => 1, 'status' => 'ready']);
        DnsCluster::query()->create(['name' => 'dns-a', 'location' => 'x', 'enabled' => true, 'api_url' => 'http://dns.test', 'api_key' => 'k', 'nameservers' => ['ns1.t', 'ns2.t'], 'last_health_status' => 'healthy']);

        $graph = app(TopologyGraph::class)->build(['clickhouse' => ['status' => 'unavailable'], 'vector' => ['status' => 'healthy']]);
        $nodes = collect($graph['nodes'])->keyBy('id');

        $this->assertSame('healthy', $nodes["edge:{$fresh->id}"]['status']);
        $this->assertSame('failed', $nodes["edge:{$stale->id}"]['status']);
        $this->assertSame('failed', $nodes['telemetry:clickhouse']['status']);
        $this->assertSame('healthy', $nodes['dns:1']['status']);
        $link = collect($graph['links'])->first(fn (array $link): bool => $link['to'] === "edge:{$stale->id}");
        $this->assertSame('failed', $link['status']);
    }

    public function test_the_page_renders_every_node_with_a_live_indicator(): void
    {
        Edge::query()->create(['name' => 'pop-visible', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.3']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Topology::class)->assertSee('pop-visible')->assertSee('Polling active')->assertSeeHtml('wire:poll.10s');
    }
}
