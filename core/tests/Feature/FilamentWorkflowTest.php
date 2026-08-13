<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Filament\Admin\Pages\Telemetry;
use App\Filament\Admin\Resources\DnsClusters\Pages\ListDnsClusters;
use App\Filament\Admin\Resources\EdgePools\Pages\EditEdgePool as EditServicePool;
use App\Filament\Admin\Resources\EdgePools\Pages\ListEdgePools;
use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Filament\Admin\Resources\Edges\Pages\CreateEdge;
use App\Filament\Admin\Resources\Edges\Pages\EditEdge;
use App\Filament\Admin\Resources\Edges\Pages\ListEdges;
use App\Filament\Admin\Resources\Edges\Pages\ViewEdge;
use App\Filament\Admin\Resources\Edges\RelationManagers\CellsRelationManager;
use App\Filament\Admin\Resources\Operations\Pages\ListOperations;
use App\Filament\Domain\Resources\Domains\Pages\CreateDomain;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\DnsRecordsRelationManager;
use App\Jobs\BuildUsageRollups;
use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Jobs\VerifyDomainNameservers;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\Operation;
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

    public function test_domain_creation_automatically_queues_zone_provisioning_and_nameserver_verification(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'Onboarding.Example.COM.'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $domain = Domain::query()->where('name', 'onboarding.example.com')->firstOrFail();
        $this->assertSame(DomainLifecycleState::PendingVerification, $domain->lifecycle_state);
        $this->assertDatabaseHas('operations', [
            'type' => 'dns.zone_reconcile',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('operations', [
            'type' => 'domain.nameservers_verify',
            'status' => 'pending',
        ]);
        Queue::assertPushed(ReconcileDnsZone::class, 1);
        Queue::assertNotPushed(VerifyDomainNameservers::class);
    }

    public function test_edge_creation_opens_a_one_time_enrollment_modal_with_exact_fleet_commands(): void
    {
        $admin = User::factory()->admin()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(CreateEdge::class)
            ->fillForm([
                'name' => 'pop-1',
                'cell_slot_count' => 8,
                'country_code' => 'IR',
                'continent_code' => 'AS',
                'management_ipv4' => '203.0.113.90',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $edge = Edge::query()->where('name', 'pop-1')->firstOrFail();
        $enrollment = session('edge_enrollment');
        $this->assertIsArray($enrollment);
        $this->assertSame((string) $edge->id, $enrollment['edge_id']);
        $this->assertSame(64, strlen($enrollment['bootstrap_token']));
        $this->assertSame(hash('sha256', $enrollment['bootstrap_token']), $edge->bootstrap_token_hash);

        Livewire::test(ViewEdge::class, ['record' => $edge->id])
            ->assertSet('defaultAction', 'showEnrollment')
            ->mountAction('showEnrollment')
            ->assertActionMounted('showEnrollment')
            ->assertMountedActionModalSee([
                (string) $edge->id,
                $enrollment['bootstrap_token'],
                'Fleet authority',
                '/root/pop-1.bootstrap-token',
                "--node 'pop-1'",
                '--edge-id '.((string) $edge->id),
            ])
            ->assertMountedActionModalSee('cdn-enrollment', false)
            ->setActionData(['saved' => true])
            ->callMountedAction()
            ->assertSet('bootstrapToken', null)
            ->assertActionNotMounted()
            ->assertActionHidden('showEnrollment');

        $this->assertNull(session('edge_enrollment'));
    }

    public function test_identity_rotation_replaces_the_token_toast_with_guided_recovery_instructions(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $edge = Edge::query()->create([
            'name' => 'pop-rotate',
            'country_code' => 'IR',
            'continent_code' => 'AS',
            'management_ipv4' => '203.0.113.91',
            'identity_hash' => hash('sha256', 'old-identity'),
            'identity_certificate_serial' => 'old-serial',
            'registered_at' => now(),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListEdges::class)
            ->assertTableActionHasUrl(
                'rotateIdentity',
                EdgeResource::getUrl('view', ['record' => $edge, 'action' => 'rotateIdentity']),
                $edge,
            );

        $component = Livewire::test(ViewEdge::class, ['record' => $edge->id])
            ->callAction('rotateIdentity', data: ['confirm_rotation' => true])
            ->assertHasNoActionErrors()
            ->assertActionMounted('showRotatedIdentity');

        $mountedActions = $component->get('mountedActions');
        $arguments = $mountedActions[array_key_last($mountedActions)]['arguments'];
        $token = $arguments['bootstrapToken'];

        $this->assertSame((string) $edge->id, $arguments['edgeId']);
        $this->assertSame('pop-rotate', $arguments['nodeName']);
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $edge->refresh()->bootstrap_token_hash);
        $this->assertNull($edge->identity_hash);
        $this->assertNull($edge->identity_certificate_serial);
        $this->assertNotNull($edge->identity_revoked_at);
        $this->assertNull($edge->registered_at);

        $component
            ->assertMountedActionModalSee([
                'The old certificate is revoked.',
                (string) $edge->id,
                $token,
                '/root/pop-rotate.bootstrap-token',
                "--node 'pop-rotate'",
                'render --node',
                '--force-recreate edge-agent',
                'clear-edge-bootstrap-token',
            ])
            ->assertMountedActionModalSee('cdn-enrollment-layout', false)
            ->assertMountedActionModalDontSee('cdn-rotation', false)
            ->assertMountedActionModalDontSee('New one-time bootstrap token')
            ->setActionData(['saved' => true])
            ->callMountedAction()
            ->assertActionNotMounted();

        Queue::assertPushed(ReconcilePlatformDnsIdentity::class);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'edge.identity_rotated',
            'subject_id' => (string) $edge->id,
        ]);
    }

    public function test_pool_policy_form_limits_replication_and_queues_reconciliation(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $reserved = EdgePool::query()->create([
            'name' => 'ui-policy', 'kind' => 'reserved', 'enabled' => true,
            'minimum_ready_cells' => 1, 'replicas_per_edge' => 1, 'maximum_domains_per_cell' => 100,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(EditServicePool::class, ['record' => $shared->id])
            ->fillForm(['replicas_per_edge' => 2])
            ->call('save')
            ->assertHasFormErrors(['replicas_per_edge']);

        Livewire::test(EditServicePool::class, ['record' => $reserved->id])
            ->fillForm(['minimum_ready_cells' => 2, 'replicas_per_edge' => 2, 'maximum_domains_per_cell' => 80])
            ->call('save')
            ->assertHasNoFormErrors();

        $operation = Operation::query()->where('type', 'edge.global_reconcile')->latest('created_at')->firstOrFail();
        $this->assertSame(['pool_id' => $reserved->id, 'reason' => 'pool_policy_changed'], $operation->input);
        Queue::assertPushed(ReconcileAllEdgeDomains::class, fn (ReconcileAllEdgeDomains $job): bool => $job->operationId === $operation->id);
    }

    public function test_managed_waf_pool_workflow_uses_the_pinned_release_without_canary_input(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(EditServicePool::class, ['record' => $pool->id])
            ->assertSee('Offer managed WAF protection')
            ->assertDontSee('WAF readiness')
            ->fillForm(['waf_capable' => true, 'waf_runtime_version' => 'operator-should-not-type-this'])
            ->call('save')
            ->assertHasNoFormErrors();

        $pool->refresh();
        $this->assertTrue($pool->waf_capable);
        $this->assertSame(config('security.waf.ruleset'), $pool->waf_runtime_version);
    }

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

    public function test_cells_show_enrollment_state_without_public_address_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $edge = Edge::query()->create([
            'name' => 'edge-ui', 'country_code' => 'IR', 'continent_code' => 'AS',
            'management_ipv4' => '203.0.113.10', 'management_ipv6' => '2001:db8::10',
        ]);
        $pool = EdgePool::query()->where('kind', 'shared')->orderBy('id')->firstOrFail();
        $cell = EdgeCell::query()->create([
            'edge_id' => $edge->id,
            'edge_pool_id' => $pool->id,
            'name' => $pool->name,
            'capacity' => [
                'cpu_usage' => 0.25,
                'memory_usage' => 67108864,
                'memory_limit' => 536870912,
                'cache_usage' => 10485760,
                'cache_limit' => 268435456,
                'temporary_storage_usage' => 1048576,
                'temporary_storage_limit' => 67108864,
            ],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        $component = fn () => Livewire::test(CellsRelationManager::class, [
            'ownerRecord' => $edge->refresh(),
            'pageClass' => EditEdge::class,
        ]);

        $component()->assertSee('Awaiting agent enrollment')
            ->assertSee($pool->name)
            ->assertSee('64.0 MiB / 512.0 MiB memory')
            ->assertSee('10.0 MiB / 256.0 MiB cache')
            ->assertSee('1.00 MiB / 64.0 MiB temporary');
        $this->assertDatabaseHas('edge_cells', [
            'id' => $cell->id,
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
                'query_policy' => 'include_all', 'query_parameters' => [], 'bypass_cookie_names' => ['session_id'],
                'status_ttl_seconds' => ['200' => 600, '404' => 30], 'admission_requests' => 2,
                'stale_if_error_seconds' => 30, 'stale_while_revalidate_seconds' => 15,
                'mode' => 'normal', 'maximum_variants_per_resource' => 16,
            ])->assertHasNoActionErrors();
        $this->assertSame(600, $domain->refresh()->cache_settings['edge_ttl_seconds']);

        $component()->callAction('developmentMode', data: ['duration_minutes' => 30])->assertHasNoActionErrors();
        $this->assertTrue($domain->refresh()->cache_development_mode_until->isFuture());

        $component()->callAction('purgeCache', data: ['type' => 'urls', 'urls' => "https://cache-ui.example.test/app.css\n"])->assertHasNoActionErrors();
        $this->assertDatabaseHas('cache_purges', ['domain_id' => $domain->id, 'type' => 'urls', 'status' => 'succeeded']);
    }

    public function test_custom_tls_mode_without_a_certificate_is_reported_as_an_action_validation_error(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create([
            'name' => 'custom-tls-ui.example.test',
            'display_name' => 'Custom TLS UI',
            'revision' => 1,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->callAction('tlsMode', data: ['mode' => 'custom'])
            ->assertHasActionErrors(['mode']);

        $this->assertSame('managed', $domain->refresh()->tls_mode);
        $this->assertSame(1, $domain->revision);
        Queue::assertNothingPushed();
    }

    public function test_security_profile_action_reacts_to_presets_and_refreshes_the_saved_profile(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'security-ui.example.test', 'display_name' => 'Security UI', 'revision' => 1]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->mountAction('securitySettings')
            ->assertSet('mountedActions.0.data.profile', 'standard')
            ->set('mountedActions.0.data.profile', 'protected')
            ->assertSet('mountedActions.0.data.limits.requests_per_second', 50)
            ->assertSet('mountedActions.0.data.limits.origin_retry_limit', 1)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('protected', $domain->refresh()->security_settings['profile']);
        $this->assertSame(2, $domain->revision);
        Queue::assertPushed(ReconcileEdgeDomain::class, fn (ReconcileEdgeDomain $job): bool => $job->domainId === $domain->id);
    }

    public function test_domain_maintenance_is_a_clear_reversible_action(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'maintenance-ui.example.test', 'display_name' => 'Maintenance UI', 'revision' => 1]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->assertSee('Start maintenance')
            ->assertSee('Enable Under Attack mode')
            ->assertSee('Web application firewall')
            ->assertDontSee('Restrict domain')
            ->callAction('startMaintenance', data: ['body' => 'Planned maintenance'])
            ->assertHasNoActionErrors();

        $this->assertSame(['status' => 503, 'body' => 'Planned maintenance'], $domain->refresh()->proxy_settings['maintenance']);

        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->assertSee('End maintenance')
            ->callAction('endMaintenance')
            ->assertHasNoActionErrors();

        $this->assertNull($domain->refresh()->proxy_settings['maintenance']);
        $this->assertSame(3, $domain->revision);
        Queue::assertPushed(ReconcileEdgeDomain::class, 1);
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
            'management_ipv4' => '203.0.113.44',
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

    public function test_administrator_can_queue_global_reconciliation_and_bounded_usage_rebuilds(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'usage-ui.example.test', 'display_name' => 'Usage UI']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListDnsClusters::class)->callAction('reconcileAllZones')->assertHasNoActionErrors();
        Livewire::test(ListEdges::class)->callAction('reconcileAllDomains')->assertHasNoActionErrors();
        Livewire::test(Telemetry::class)->callAction('rebuildUsage', data: [
            'domain_id' => $domain->id,
            'from' => '2026-07-20 08:00:00',
            'to' => '2026-07-20 10:00:00',
        ])->assertHasNoActionErrors();

        $this->assertSame(1, Operation::query()->where('type', 'dns.global_reconcile')->count());
        $this->assertSame(1, Operation::query()->where('type', 'edge.global_reconcile')->count());
        $this->assertDatabaseHas('operations', [
            'type' => 'usage.rebuild',
            'actor_id' => $admin->id,
        ]);
        Queue::assertPushed(ReconcileAllDnsZones::class);
        Queue::assertPushed(ReconcileAllEdgeDomains::class);
        Queue::assertPushed(BuildUsageRollups::class);
    }

    public function test_domain_delivery_status_lists_active_and_target_cells_by_edge(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'cells-ui.example.test', 'display_name' => 'Cells UI', 'revision' => 4]);
        $source = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $target = EdgePool::query()->where('kind', 'quarantine')->firstOrFail();
        $edge = Edge::query()->create(['name' => 'cells-ui-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.70']);
        $activeCell = $edge->cells()->create(['slot' => 1, 'edge_pool_id' => $source->id, 'status' => 'assigned']);
        $targetCell = $edge->cells()->create(['slot' => 2, 'edge_pool_id' => $target->id, 'status' => 'assigned']);
        DomainEdgePlacement::query()->create(['domain_id' => $domain->id, 'active_pool_id' => $source->id, 'target_pool_id' => $target->id, 'desired_revision' => 4, 'state' => 'deploying']);
        DomainEdgeCell::query()->create([
            'domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => 1,
            'active_cell_id' => $activeCell->id, 'target_cell_id' => $targetCell->id,
            'desired_revision' => 4, 'state' => 'deploying',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->assertSee('Active cells by edge')->assertSee('Target cells by edge')
            ->assertSee('cells-ui-edge · replica 1 · cell-01 · deploying')
            ->assertSee('cells-ui-edge · replica 1 · cell-02 · deploying');
    }

    public function test_administrator_can_assign_a_free_cell_from_the_edge_screen(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $pool = EdgePool::query()->create(['name' => 'ui-cell-target', 'kind' => 'reserved', 'enabled' => false]);
        $edge = Edge::query()->create(['name' => 'ui-free-cell-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.80']);
        $cell = $edge->cells()->create(['slot' => 2, 'status' => 'unassigned']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $component = fn () => Livewire::test(CellsRelationManager::class, [
            'ownerRecord' => $edge,
            'pageClass' => EditEdge::class,
        ]);
        $component()->callTableAction('assignPool', $cell, [
            'edge_pool_id' => $pool->id,
        ])->assertHasNoFormErrors();

        $this->assertDatabaseHas('edge_cells', [
            'id' => $cell->id, 'edge_pool_id' => $pool->id, 'status' => 'assigned',
        ]);
        $operation = Operation::query()->where('type', 'edge.global_reconcile')->firstOrFail();
        $this->assertSame(['pool_id' => $pool->id, 'cell_id' => $cell->id], $operation->input);
        Queue::assertPushed(ReconcileAllEdgeDomains::class, fn (ReconcileAllEdgeDomains $job): bool => $job->operationId === $operation->id);
    }

    public function test_administrator_can_unassign_a_pool_from_an_edge_cell(): void
    {
        $admin = User::factory()->admin()->create();
        $pool = EdgePool::query()->create([
            'name' => 'ui-cell-remove', 'kind' => 'shared', 'routing_mode' => 'simple_anycast',
            'anycast_ipv4' => '198.51.100.80', 'enabled' => false,
        ]);
        $edge = Edge::query()->create(['name' => 'ui-remove-cell-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.81']);
        $cell = $edge->cells()->create(['slot' => 3, 'edge_pool_id' => $pool->id, 'status' => 'assigned']);
        $pool->endpoints()->create(['edge_id' => $edge->id, 'revision' => 1, 'gateway_state' => 'pending']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(CellsRelationManager::class, [
            'ownerRecord' => $edge,
            'pageClass' => EditEdge::class,
        ])->assertSee('Unassign service pool')
            ->callTableAction('unassignPool', $cell)
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('edge_cells', ['id' => $cell->id, 'edge_pool_id' => null, 'status' => 'unassigned']);
        $this->assertDatabaseMissing('edge_pool_endpoints', ['edge_id' => $edge->id, 'edge_pool_id' => $pool->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'edge.pool_cell_unassigned']);
    }

    public function test_administrator_can_move_a_domain_to_another_service_pool(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'move-ui.example.test', 'display_name' => 'Move UI', 'revision' => 5]);
        $domain->dnsRecords()->create(DnsRecordData::validate([
            'type' => 'A', 'name' => 'www', 'content' => '192.0.2.25', 'ttl' => 300, 'mode' => 'proxied',
            'origin' => [
                'host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'www.move-ui.example.test',
                'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 2000, 'response_timeout_ms' => 30000,
                'retry_count' => 0, 'websocket' => false, 'health_check' => null,
            ],
        ], $domain->name));
        $source = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $target = EdgePool::query()->create(['name' => 'move-ui-target', 'kind' => 'reserved', 'enabled' => true]);
        DomainEdgePlacement::query()->create([
            'domain_id' => $domain->id, 'active_pool_id' => $source->id,
            'desired_revision' => $domain->revision, 'state' => 'active',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->callAction('moveEdgePool', data: ['pool_id' => $target->id])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('domain_edge_placements', [
            'domain_id' => $domain->id, 'active_pool_id' => $source->id, 'target_pool_id' => $target->id,
            'desired_revision' => 6, 'state' => 'deploying',
        ]);
        $operation = Operation::query()->where('type', 'edge.domain_reconcile')->firstOrFail();
        $this->assertSame(['domain_id' => $domain->id], $operation->input);
        Queue::assertPushed(ReconcileEdgeDomain::class, fn (ReconcileEdgeDomain $job): bool => $job->domainId === $domain->id);
    }

    public function test_operation_copy_control_contains_the_complete_identifier(): void
    {
        $admin = User::factory()->admin()->create();
        $operation = Operation::query()->create([
            'actor_id' => $admin->id, 'type' => 'edge.global_reconcile', 'status' => 'pending', 'input' => [],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $html = Livewire::test(ListOperations::class)->html();

        $this->assertMatchesRegularExpression(
            '/navigator\\.clipboard\\.writeText\\([^)]*'.preg_quote($operation->id, '/').'/s',
            html_entity_decode($html),
        );
    }

    public function test_service_pool_list_has_no_global_cell_assignment_action(): void
    {
        $admin = User::factory()->admin()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $this->assertStringNotContainsString('Reconcile cells', Livewire::test(ListEdgePools::class)->html());
        $this->assertDatabaseMissing('operations', ['type' => 'edge.pool_provision']);
    }

    public function test_administrator_can_delete_an_empty_disabled_service_pool(): void
    {
        $admin = User::factory()->admin()->create();
        $pool = EdgePool::query()->create(['name' => 'ui-delete-pool', 'kind' => 'shared', 'enabled' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListEdgePools::class)
            ->callTableAction('delete', $pool)
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('edge_pools', ['id' => $pool->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'edge.pool_deleted', 'subject_id' => (string) $pool->id]);
    }

    public function test_domain_dns_reconcile_action_reuses_the_policy_aware_endpoint(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $domain = Domain::query()->create([
            'name' => 'reconcile-ui.example.test',
            'display_name' => 'Reconcile UI',
            'lifecycle_state' => DomainLifecycleState::Active,
            'revision' => 3,
        ]);
        $domain->users()->attach($user);
        $domain->dnsRecords()->create(DnsRecordData::validate([
            'type' => 'A',
            'name' => 'www',
            'content' => '192.0.2.20',
            'ttl' => 300,
            'mode' => 'proxied',
            'origin' => [
                'host' => '8.8.8.8', 'port' => 443, 'scheme' => 'https',
                'host_header' => 'www.reconcile-ui.example.test', 'sni' => 'www.reconcile-ui.example.test',
                'verify_tls' => true, 'connect_timeout_ms' => 2000, 'response_timeout_ms' => 30000,
                'retry_count' => 0, 'websocket' => false, 'health_check' => null,
            ],
        ], $domain->name));
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($user);

        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->callAction('reconcileDns')->assertHasNoActionErrors();

        $this->assertDatabaseHas('operations', ['type' => 'dns.zone_reconcile', 'actor_id' => $user->id]);
        Queue::assertPushed(ReconcileDnsZone::class, fn (ReconcileDnsZone $job): bool => $job->domainId === $domain->id);
    }
}
