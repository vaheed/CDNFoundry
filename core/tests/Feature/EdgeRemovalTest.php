<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Edges\Pages\ListEdges;
use App\Filament\Admin\Resources\Edges\Pages\ViewEdge;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\Edge;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EdgeRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_remove_a_disabled_drained_edge_and_queue_dns_cleanup(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $edge = Edge::query()->create(['name' => 'retired-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => false, 'drained' => true]);
        $cell = $edge->cells()->create(['slot' => 1, 'status' => 'ready']);
        $settings = PlatformDnsSetting::query()->create([
            'id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [], 'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test',
            'soa_refresh' => 3600, 'soa_retry' => 600, 'soa_expire' => 1209600,
            'soa_minimum_ttl' => 300, 'default_ttl' => 300,
        ]);

        Livewire::test(ListEdges::class)->callTableAction('remove', $edge)->assertRedirect('/admin/edges');

        $this->assertModelMissing($edge);
        $this->assertModelMissing($cell);
        $this->assertDatabaseHas('audit_logs', ['action' => 'edge.deleted', 'subject_id' => (string) $edge->id]);
        Queue::assertPushed(ReconcilePlatformDnsIdentity::class);
        $this->assertSame(2, $settings->refresh()->revision);
    }

    public function test_remove_is_hidden_for_enabled_edges_and_disabled_until_drained(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $edge = Edge::query()->create(['name' => 'active-edge', 'country_code' => 'DE', 'continent_code' => 'EU']);
        Livewire::test(ListEdges::class)->assertTableActionHidden('remove', $edge);
        $edge->update(['enabled' => false]);
        Livewire::test(ListEdges::class)->assertTableActionDisabled('remove', $edge);
        $this->assertModelExists($edge);
    }

    public function test_edge_detail_can_remove_a_retired_edge(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $edge = Edge::query()->create(['name' => 'retired-detail-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => false, 'drained' => true]);
        Livewire::test(ViewEdge::class, ['record' => $edge->id])->callAction('remove')->assertRedirect('/admin/edges');
        $this->assertModelMissing($edge);
    }

    public function test_domain_user_cannot_open_edge_administration(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/edges')->assertForbidden();
    }

    public function test_confirmation_rechecks_an_edge_reenabled_by_another_administrator(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $edge = Edge::query()->create(['name' => 'changed-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => false, 'drained' => true]);
        $page = Livewire::test(ListEdges::class)->mountTableAction('remove', $edge);
        $edge->update(['enabled' => true]);

        $page->callMountedTableAction();

        $this->assertModelExists($edge);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'edge.deleted', 'subject_id' => (string) $edge->id]);
        Queue::assertNotPushed(ReconcilePlatformDnsIdentity::class);
    }
}
