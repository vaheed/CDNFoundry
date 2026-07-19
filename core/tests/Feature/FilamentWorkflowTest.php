<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Filament\Admin\Resources\Edges\Pages\EditEdge;
use App\Filament\Admin\Resources\Edges\RelationManagers\CellsRelationManager;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\DnsRecordsRelationManager;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\User;
use App\Support\DnsRecordData;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_cname_without_a_continent_saves_and_owner_conflicts_are_visible(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'geo-ui.example.test', 'display_name' => 'Geo UI', 'revision' => 1]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(DnsRecordsRelationManager::class, [
            'ownerRecord' => $domain,
            'pageClass' => ViewDomain::class,
        ])->mountTableAction('create')
            ->set('mountedActions.0.data.type', 'CNAME')
            ->set('mountedActions.0.data.mode', 'geo_dns')
            ->set('mountedActions.0.data.name', 'regional')
            ->set('mountedActions.0.data.geo_default', ['default.example.net'])
            ->set('mountedActions.0.data.geo_countries', [['code' => 'IR', 'targets' => ['iran.example.net']]])
            ->set('mountedActions.0.data.geo_continents', [])
            ->set('mountedActions.0.data.ttl', 300)
            ->callMountedTableAction()
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('dns_records', [
            'domain_id' => $domain->id,
            'type' => 'CNAME',
            'name' => 'regional.geo-ui.example.test',
            'mode' => 'geo_dns',
        ]);
        $record = $domain->dnsRecords()->where('name', 'regional.geo-ui.example.test')->firstOrFail();
        $this->assertSame([], $record->geo_config['continents']);
        $this->assertSame(['iran.example.net.'], $record->geo_config['countries']['IR']);

        $domain->dnsRecords()->create(DnsRecordData::validate([
            'type' => 'A', 'name' => 'occupied', 'content' => '192.0.2.20', 'ttl' => 300,
        ], $domain->name));
        Livewire::test(DnsRecordsRelationManager::class, [
            'ownerRecord' => $domain->refresh(),
            'pageClass' => ViewDomain::class,
        ])->mountTableAction('create')
            ->set('mountedActions.0.data.type', 'CNAME')
            ->set('mountedActions.0.data.mode', 'geo_dns')
            ->set('mountedActions.0.data.name', 'occupied')
            ->set('mountedActions.0.data.geo_default', ['default.example.net'])
            ->set('mountedActions.0.data.geo_countries', [['code' => 'IR', 'targets' => ['iran.example.net']]])
            ->set('mountedActions.0.data.geo_continents', [])
            ->set('mountedActions.0.data.ttl', 300)
            ->callMountedTableAction()
            ->assertHasFormErrors(['name']);
    }

    public function test_plain_http_proxy_form_normalizes_tls_fields_and_reports_origin_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'proxy-ui.example.test', 'display_name' => 'Proxy UI', 'revision' => 1]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        $component = fn () => Livewire::test(DnsRecordsRelationManager::class, [
            'ownerRecord' => $domain->refresh(),
            'pageClass' => ViewDomain::class,
        ]);
        $input = [
            'type' => 'A',
            'mode' => 'proxied',
            'name' => 'www',
            'origin' => [
                'host' => '8.8.8.8',
                'scheme' => 'http',
                'port' => 443,
                'host_header' => 'www.proxy-ui.example.test',
                'sni' => 'must-be-removed.example.test',
                'verify_tls' => true,
                'connect_timeout_ms' => 2000,
                'response_timeout_ms' => 30000,
                'retry_count' => 0,
                'websocket' => false,
                'health_check' => ['enabled' => false, 'path' => '/', 'interval_seconds' => 300],
            ],
            'ttl' => 300,
        ];

        $component()->callTableAction('create', null, $input)->assertHasNoFormErrors();
        $origin = $domain->dnsRecords()->where('name', 'www.proxy-ui.example.test')->firstOrFail()->origin;
        $this->assertSame(80, $origin['port']);
        $this->assertFalse($origin['verify_tls']);
        $this->assertNull($origin['sni']);

        $invalid = $input;
        $invalid['name'] = 'blocked';
        $invalid['origin']['host'] = '127.0.0.1';
        $component()->callTableAction('create', null, $invalid)->assertHasFormErrors(['origin.host']);
    }

    public function test_cells_show_enrollment_state_and_use_the_same_address_rules_as_the_api(): void
    {
        $admin = User::factory()->admin()->create();
        $edge = Edge::query()->create([
            'name' => 'edge-ui', 'country_code' => 'IR', 'continent_code' => 'AS',
            'ipv4' => '203.0.113.10', 'ipv6' => '2001:db8::10',
        ]);
        $pool = EdgePool::query()->where('kind', 'shared')->orderBy('id')->firstOrFail();
        $cell = EdgeCell::query()->create([
            'edge_id' => $edge->id,
            'edge_pool_id' => $pool->id,
            'name' => $pool->name,
            'service_ipv4' => $edge->ipv4,
            'service_ipv6' => $edge->ipv6,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        $component = fn () => Livewire::test(CellsRelationManager::class, [
            'ownerRecord' => $edge->refresh(),
            'pageClass' => EditEdge::class,
        ]);

        $component()->assertSee('Awaiting agent enrollment')->assertSee('Awaiting heartbeat');
        $component()->callTableAction('edit', $cell, [
            'service_ipv4' => '10.0.0.10',
            'service_ipv6' => '2001:db8::11',
        ])->assertHasFormErrors(['service_ipv4']);
        $component()->callTableAction('edit', $cell, [
            'service_ipv4' => '203.0.113.11',
            'service_ipv6' => '2001:db8::11',
        ])->assertHasNoFormErrors();

        $this->assertDatabaseHas('edge_cells', [
            'id' => $cell->id,
            'service_ipv4' => '203.0.113.11',
            'service_ipv6' => '2001:db8::11',
        ]);
    }

    public function test_cache_actions_save_visible_bounded_state_and_queue_purges(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'cache-ui.example.test', 'display_name' => 'Cache UI', 'revision' => 1]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        $component = fn () => Livewire::test(ViewDomain::class, ['record' => $domain->id]);

        $component()->assertSee('Cache UI')->assertSee('cache-ui.example.test')
            ->assertSee('Domain actions')->assertSee('Delivery')->assertSee('Cache')->assertSee('TLS')
            ->assertDontSee('Deploy proxy configuration')
            ->assertSee('Cache settings')->assertSee('Enable development mode')->assertSee('Purge cache')
            ->callAction('cacheSettings', data: [
                'enabled' => true, 'edge_ttl_seconds' => 600, 'browser_ttl_seconds' => 120,
                'maximum_object_bytes' => 104857600, 'respect_origin_headers' => true,
                'include_query_string' => true, 'bypass_cookie_names' => ['session_id'], 'stale_if_error_seconds' => 30,
            ])->assertHasNoActionErrors();
        $this->assertSame(600, $domain->refresh()->cache_settings['edge_ttl_seconds']);

        $component()->callAction('developmentMode', data: ['duration_minutes' => 30])->assertHasNoActionErrors();
        $this->assertTrue($domain->refresh()->cache_development_mode_until->isFuture());

        $component()->callAction('purgeCache', data: ['type' => 'urls', 'urls' => "https://cache-ui.example.test/app.css\n"])->assertHasNoActionErrors();
        $this->assertDatabaseHas('cache_purges', ['domain_id' => $domain->id, 'type' => 'urls', 'status' => 'succeeded']);
    }

    public function test_disabling_from_the_domain_panel_automatically_queues_edge_reconciliation(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create([
            'name' => 'disable-ui.example.test',
            'display_name' => 'Disable UI',
            'lifecycle_state' => DomainLifecycleState::Active,
            'revision' => 4,
        ]);
        $edge = Edge::query()->create([
            'name' => 'disable-ui-edge', 'country_code' => 'IR', 'continent_code' => 'AS',
            'ipv4' => '203.0.113.44',
        ]);
        EdgeArtifact::query()->create([
            'edge_id' => $edge->id, 'domain_id' => $domain->id, 'revision' => 4, 'kind' => 'domain',
            'payload' => ['revision' => 4], 'checksum' => str_repeat('a', 64), 'signature' => str_repeat('b', 64),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->callAction('disable')
            ->assertHasNoActionErrors();

        $this->assertSame(DomainLifecycleState::Disabled, $domain->refresh()->lifecycle_state);
        $this->assertSame(5, $domain->revision);
        Queue::assertPushed(ReconcileEdgeDomain::class, fn (ReconcileEdgeDomain $job): bool => $job->domainId === $domain->id);
    }
}
