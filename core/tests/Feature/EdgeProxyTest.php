<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgePool;
use App\Models\EdgeRevision;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\ArtifactSigner;
use App\Support\PowerDnsZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EdgeProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_pool_move_publishes_latest_state_when_its_requested_revision_is_superseded(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $domain->update(['lifecycle_state' => 'active']);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('www', '8.8.8.8'))->assertCreated();
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $quarantine = EdgePool::query()->where('kind', 'quarantine')->firstOrFail();
        DomainEdgePlacement::query()->create([
            'domain_id' => $domain->id,
            'active_pool_id' => $shared->id,
            'desired_revision' => $domain->refresh()->revision,
            'state' => 'active',
        ]);
        $edge = Edge::query()->create([
            'name' => 'coalesced-move-edge', 'country_code' => 'IR', 'continent_code' => 'AS',
            'ipv4' => '203.0.113.45',
        ]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/move", ['pool_id' => $quarantine->id])->assertAccepted();
        $moveRevision = $domain->refresh()->revision;

        $domain->update(['revision' => $moveRevision + 1]);
        (new ReconcileEdgeDomain($domain->id))->handle();

        $this->assertDatabaseMissing('edge_revisions', ['domain_id' => $domain->id, 'revision' => $moveRevision]);
        $this->assertDatabaseHas('domain_edge_placements', [
            'domain_id' => $domain->id,
            'active_pool_id' => $shared->id,
            'target_pool_id' => $quarantine->id,
            'desired_revision' => $moveRevision + 1,
            'state' => 'deploying',
        ]);
        $artifact = EdgeArtifact::query()->where('edge_id', $edge->id)->where('domain_id', $domain->id)->firstOrFail();
        $this->assertSame($moveRevision + 1, $artifact->revision);
        $this->assertSame(['quarantine-default', 'shared-default'], $artifact->payload['pools']);
    }

    public function test_only_address_and_cname_records_can_be_proxied_without_dns_content(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $origin = $this->origin('8.8.8.8');
        $origin['host_header'] = $domain->name;
        $origin['health_check'] = ['enabled' => false];

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", [
            'type' => 'TXT', 'name' => '@', 'ttl' => 300, 'mode' => 'proxied', 'origin' => $origin,
        ])->assertUnprocessable()->assertJsonValidationErrors('mode');

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", [
            'type' => 'A', 'name' => '@', 'ttl' => 300, 'mode' => 'proxied', 'origin' => $origin,
        ])->assertCreated()->assertJsonPath('data.content', '8.8.8.8')->assertJsonPath('data.origin.health_check', null);
    }

    public function test_first_edge_registration_backfills_proxy_state_saved_before_the_edge_existed(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('waiting', '8.8.8.8'))
            ->assertCreated();
        $this->assertSame(0, EdgeArtifact::query()->where('domain_id', $domain->id)->count());

        $admin = User::factory()->admin()->create();
        $created = $this->actingAs($admin)->postJson('/api/admin/edges', [
            'name' => 'first-edge', 'country_code' => 'IR', 'continent_code' => 'AS',
            'ipv4' => '203.0.113.40', 'ipv6' => '2001:db8::40',
        ])->assertCreated();
        $edgeId = $created->json('data.id');
        $this->postJson('/edge/v1/register', [
            'edge_id' => $edgeId,
            'bootstrap_token' => $created->json('data.bootstrap_token'),
            'agent_version' => '1.0.0',
            'certificate_request' => $this->certificateRequest($edgeId),
        ])->assertCreated();

        $this->assertDatabaseHas('edge_artifacts', [
            'edge_id' => $edgeId,
            'domain_id' => $domain->id,
            'revision' => $domain->refresh()->revision,
            'kind' => 'domain',
        ]);
    }

    public function test_proxied_hosts_have_distinct_safe_origins_and_revisioned_artifacts(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $first = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('www', '8.8.8.8'))
            ->assertCreated()->assertJsonPath('data.mode', 'proxied')->json('data.id');
        $second = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('api', '1.1.1.1'))
            ->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertSame('8.8.8.8', $domain->dnsRecords()->find($first)->origin['host']);
        $this->assertSame('1.1.1.1', $domain->dnsRecords()->find($second)->origin['host']);
        $this->assertDatabaseHas('domain_edge_placements', ['domain_id' => $domain->id, 'state' => 'deploying']);
        $this->assertSame(2, EdgeRevision::query()->where('domain_id', $domain->id)->count());
        $this->assertDatabaseHas('operations', ['type' => 'edge.domain_reconcile', 'actor_id' => $user->id, 'status' => 'running']);

        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/dns/records/$first/origin", array_merge($this->origin('9.9.9.9'), ['host' => '127.0.0.1']))->assertUnprocessable();
        config()->set('edge.private_origin_allowlist', ['0.0.0.0/0', '::/0']);
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/dns/records/$first/origin", array_merge($this->origin('9.9.9.9'), ['host' => '169.254.169.254']))->assertUnprocessable();
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/dns/records/$first/origin", array_merge($this->origin('9.9.9.9'), ['host' => '::ffff:127.0.0.1']))->assertUnprocessable();
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/dns/records/$first/origin", $this->origin('9.9.9.9'))->assertAccepted();
        $this->assertSame('9.9.9.9', $domain->dnsRecords()->find($first)->origin['host']);
    }

    public function test_one_hostname_cannot_have_multiple_proxy_records_or_origins(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('www', '8.8.8.8'))->assertCreated();
        $duplicateFamily = $this->record('www', '1.1.1.1');
        $duplicateFamily['type'] = 'AAAA';
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $duplicateFamily)
            ->assertUnprocessable()->assertJsonValidationErrors('records');
        $this->assertSame(1, $domain->dnsRecords()->where('name', 'www.'.$domain->name)->count());
    }

    public function test_apex_proxy_coexists_with_mail_and_text_records_and_renders_pool_routing(): void
    {
        [$user, $domain] = $this->ownedDomain();
        foreach ([
            ['type' => 'MX', 'name' => '@', 'ttl' => 300, 'priority' => 10, 'mode' => 'geo_dns', 'geo' => [
                'default' => ['mail.example.net.'], 'countries' => ['IR' => ['mail-ir.example.net.']],
            ]],
            ['type' => 'TXT', 'name' => '@', 'ttl' => 300, 'mode' => 'geo_dns', 'geo' => [
                'default' => ['verification=present'], 'countries' => ['IR' => ['verification=ir']],
            ]],
            ['type' => 'CAA', 'name' => '@', 'content' => '0 issue letsencrypt.org', 'ttl' => 300],
        ] as $record) {
            $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $record)->assertCreated();
        }

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('@', '8.8.8.8'))->assertCreated();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('www', '1.1.1.1'))->assertCreated();

        $competingAddress = ['type' => 'AAAA', 'name' => '@', 'content' => '2001:db8::99', 'ttl' => 300];
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $competingAddress)
            ->assertUnprocessable()
            ->assertJsonPath('errors.records.0', "A proxied hostname cannot coexist with another A, AAAA, or CNAME at {$domain->name}. Edit or remove the existing address/alias record.");
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", [
            'type' => 'TXT', 'name' => 'www', 'content' => 'cannot-coexist', 'ttl' => 300,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.records.0', "A non-apex proxied hostname publishes as a CNAME and must be the only record at www.{$domain->name}.");

        $settings = PlatformDnsSetting::query()->create([
            'id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [
                ['hostname' => 'ns1.cdnf.test', 'ipv4' => '192.0.2.1', 'ipv6' => '2001:db8::1'],
                ['hostname' => 'ns2.cdnf.test', 'ipv4' => '192.0.2.2', 'ipv6' => '2001:db8::2'],
            ],
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test',
            'soa_refresh' => 3600, 'soa_retry' => 600, 'soa_expire' => 1209600,
            'soa_minimum_ttl' => 300, 'default_ttl' => 300, 'cluster_targets' => ['pdns.test'],
        ]);
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        DomainEdgePlacement::query()->updateOrCreate(['domain_id' => $domain->id], [
            'active_pool_id' => $pool->id, 'target_pool_id' => null, 'state' => 'active',
            'desired_revision' => $domain->refresh()->revision,
        ]);

        $rows = collect(PowerDnsZone::render($domain->refresh()));
        $apexRows = $rows->where('name', $domain->name.'.');
        $this->assertSame(['A', 'AAAA', 'MX', 'TXT'], $apexRows->where('type', 'LUA')->flatMap(fn (array $row): array => $row['records'])
            ->pluck('content')->map(fn (string $content): string => strtok($content, ' '))->sort()->values()->all());
        $this->assertSame(['CAA', 'NS', 'SOA'], $apexRows->where('type', '!=', 'LUA')->pluck('type')->sort()->unique()->values()->all());
        $this->assertSame('pool-'.$pool->id.'.'.$settings->proxy_hostname.'.', $rows->firstWhere('name', 'www.'.$domain->name.'.')['records'][0]['content']);
    }

    public function test_edge_bootstrap_is_one_time_and_artifacts_require_active_identity(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $created = $this->actingAs($admin)->postJson('/api/admin/edges', ['name' => 'edge-ir-1', 'country_code' => 'IR', 'continent_code' => 'AS', 'ipv4' => '203.0.113.10', 'ipv6' => '2001:db8::10'])
            ->assertCreated();
        $id = $created->json('data.id');
        $bootstrap = $created->json('data.bootstrap_token');
        $registration = ['edge_id' => $id, 'bootstrap_token' => $bootstrap, 'agent_version' => '1.0.0', 'certificate_request' => $this->certificateRequest($id)];
        $registered = $this->postJson('/edge/v1/register', $registration)->assertCreated();
        $identity = $this->edgeIdentityHeaders($registered->json('data.identity_certificate_serial'));
        $this->postJson('/edge/v1/register', $registration)->assertCreated()
            ->assertJsonPath('data.identity_certificate_serial', $registered->json('data.identity_certificate_serial'));
        $differentRegistration = [...$registration, 'certificate_request' => $this->certificateRequest($id)];
        $this->postJson('/edge/v1/register', $differentRegistration)->assertUnauthorized();
        $this->withHeaders($identity)->postJson('/edge/v1/heartbeat', ['agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => 0, 'cells' => [
            ['name' => 'shared-default', 'status' => 'ready', 'capacity' => ['active_connections' => 0, 'memory_usage' => 0]],
        ]])->assertOk();
        $this->postJson('/edge/v1/register', $registration)->assertUnauthorized();
        $this->assertDatabaseHas('edge_cells', ['edge_id' => $id, 'name' => 'shared-default', 'status' => 'ready']);

        [$user, $domain] = $this->ownedDomain();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('edge-loop', '203.0.113.10'))->assertUnprocessable();
        $record = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('www', '8.8.8.8'))->assertCreated()->json('data.id');
        $artifact = EdgeArtifact::query()->where('edge_id', $id)->firstOrFail();
        $this->assertTrue(sodium_crypto_sign_verify_detached(hex2bin($artifact->signature), $artifact->checksum, hex2bin($registered->json('data.signing_public_key'))));
        $this->withHeaders($identity)->getJson("/edge/v1/config/artifacts/{$artifact->checksum}")->assertOk()
            ->assertJsonPath('encoded_payload', base64_encode(ArtifactSigner::encode($artifact->payload)));
        $this->withHeaders($identity)->getJson('/edge/v1/config/manifest?cursor=0')->assertOk()->assertJsonPath('data.0.checksum', $artifact->checksum);
        $full = $this->withHeaders($identity)->getJson('/edge/v1/config/full')->assertOk()
            ->assertJsonPath('data.artifact_count', 1)->assertJsonPath('encoding', 'gzip');
        $snapshot = json_decode(gzdecode(base64_decode($full->json('encoded_snapshot'), true)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($domain->refresh()->revision, $snapshot['artifacts'][0]['revision']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(hex2bin($full->json('signature')), $full->json('checksum'), hex2bin($full->json('signing_public_key'))));
        $this->withHeaders($identity)->postJson('/edge/v1/config/applied', ['sequence' => $artifact->sequence + 100])->assertUnprocessable();
        $this->withHeaders($identity)->postJson('/edge/v1/config/applied', ['sequence' => $artifact->sequence])->assertOk();
        $this->assertDatabaseHas('domain_edge_placements', ['domain_id' => $domain->id, 'state' => 'active', 'desired_revision' => $domain->revision]);
        $this->assertSame($domain->revision, $domain->refresh()->active_edge_revision);
        $artifactCount = EdgeArtifact::query()->where('domain_id', $domain->id)->count();
        $hostname = $domain->dnsRecords()->findOrFail($record)->name;
        $this->withHeaders($identity)->postJson('/edge/v1/heartbeat', ['agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => $artifact->sequence, 'cells' => [
            ['name' => 'shared-default', 'status' => 'ready', 'capacity' => ['active_connections' => 1]],
        ], 'passive_origins' => [[
            'domain' => $domain->name, 'hostname' => $hostname, 'failure_count' => 2,
            'last_status' => 502, 'last_failed_at' => now()->timestamp,
        ]]])->assertOk();
        $this->assertSame('passive', $domain->dnsRecords()->findOrFail($record)->origin_health['source']);
        $this->assertSame($artifactCount, EdgeArtifact::query()->where('domain_id', $domain->id)->count());
        $this->actingAs($admin)->getJson('/api/admin/edge-routing')->assertOk()->assertJsonPath('data.global.0.id', $id);
        $cell = Edge::query()->findOrFail($id)->cells()->firstOrFail();
        $this->actingAs($admin)->postJson("/api/admin/edge-cells/{$cell->id}/drain")->assertAccepted();
        $this->assertTrue($cell->refresh()->drained);
        $this->actingAs($admin)->postJson("/api/admin/edge-cells/{$cell->id}/undrain")->assertAccepted();
        $this->assertFalse($cell->refresh()->drained);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/deploy")->assertAccepted();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/deploy")->assertAccepted();
        $this->assertSame(1, EdgeArtifact::query()->where('edge_id', $id)->where('domain_id', $domain->id)->count());
        $this->assertDatabaseHas('domain_edge_placements', ['domain_id' => $domain->id, 'state' => 'active']);

        $validatedRevision = $domain->revision;
        $extraRecord = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('rollback-extra', '1.1.1.1'))->assertCreated()->json('data.id');
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/dns/records/$record/origin", $this->origin('9.9.9.9'))->assertAccepted();
        $beforeRollback = $domain->refresh()->revision;
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => $validatedRevision])->assertAccepted();
        $this->assertSame($beforeRollback + 1, $domain->refresh()->revision);
        $this->assertSame('8.8.8.8', $domain->dnsRecords()->findOrFail($record)->origin['host']);
        $this->assertNull($domain->dnsRecords()->find($extraRecord));
        $this->assertDatabaseHas('audit_logs', ['action' => 'proxy.revision_rolled_back', 'subject_id' => (string) $domain->id]);

        $poolResponse = $this->actingAs($admin)->postJson('/api/admin/edge-pools', ['name' => 'dedicated-test', 'kind' => 'dedicated'])->assertAccepted();
        $pool = $poolResponse->json('data.pool.id');
        $dedicatedCell = Edge::query()->findOrFail($id)->cells()->where('edge_pool_id', $pool)->firstOrFail();
        $this->actingAs($admin)->patchJson("/api/admin/edge-cells/{$dedicatedCell->id}", [
            'service_ipv4' => '203.0.113.20', 'service_ipv6' => '2001:db8::20',
        ])->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/edge-pools/{$pool}/enable")->assertOk();
        $spectator = Edge::query()->create([
            'name' => 'edge-without-dedicated-addresses',
            'country_code' => 'DE',
            'continent_code' => 'EU',
            'ipv4' => '203.0.113.30',
            'ipv6' => '2001:db8::30',
            'registered_at' => now(),
            'last_heartbeat_at' => now(),
            'agent_version' => '1.0.0',
            'capacity' => ['listener_ready' => true],
        ]);
        $sharedPool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $spectator->cells()->create([
            'edge_pool_id' => $sharedPool->id,
            'name' => $sharedPool->name,
            'status' => 'ready',
            'service_ipv4' => $spectator->ipv4,
            'service_ipv6' => $spectator->ipv6,
        ]);
        $spectator->cells()->create([
            'edge_pool_id' => $pool,
            'name' => 'dedicated-test',
            'status' => 'ready',
            'service_ipv4' => null,
            'service_ipv6' => null,
        ]);
        $move = $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/move", ['pool_id' => $pool])->assertAccepted();
        $operation = Operation::query()->findOrFail($move->json('data.operation_id'));
        $moveArtifact = EdgeArtifact::query()->where('edge_id', $id)->where('domain_id', $domain->id)->latest('sequence')->firstOrFail();
        $this->withHeaders($identity)->postJson('/edge/v1/heartbeat', ['agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => $artifact->sequence, 'cells' => [
            ['name' => 'shared-default', 'status' => 'ready', 'capacity' => ['active_connections' => 1]],
            ['name' => 'dedicated-test', 'status' => 'ready', 'capacity' => ['active_connections' => 0]],
        ]])->assertOk();
        $this->withHeaders($identity)->postJson('/edge/v1/config/applied', ['sequence' => $moveArtifact->sequence])->assertOk();
        $this->assertDatabaseHas('domain_edge_placements', ['domain_id' => $domain->id, 'active_pool_id' => 1, 'target_pool_id' => $pool, 'state' => 'draining']);
        $this->assertNull($domain->edgePlacement->refresh()->drain_after, 'The source drain must not begin before target DNS deployment succeeds.');
        $this->assertSame('running', $operation->refresh()->status);
        $moveRevision = $domain->refresh()->revision;
        $scheduledDrain = now()->addMinutes(5)->startOfSecond();
        DomainEdgePlacement::query()->where('domain_id', $domain->id)->update(['drain_after' => $scheduledDrain]);
        (new ReconcileEdgeDomain($domain->id))->handle();
        $placement = $domain->edgePlacement->refresh();
        $this->assertSame('draining', $placement->state, 'A duplicate reconcile must not restart an acknowledged migration.');
        $this->assertTrue($placement->drain_after->equalTo($scheduledDrain), 'A duplicate reconcile must preserve the scheduled source drain.');
        DomainEdgePlacement::query()->where('domain_id', $domain->id)->update(['drain_after' => now()->subSecond()]);
        $this->artisan('edge:complete-placement-drains')->assertSuccessful();
        $this->assertSame($moveRevision + 1, $domain->refresh()->revision);
        $this->assertDatabaseHas('domain_edge_placements', [
            'domain_id' => $domain->id,
            'active_pool_id' => $pool,
            'target_pool_id' => $pool,
            'desired_revision' => $moveRevision + 1,
            'state' => 'deploying',
        ]);
        $drainedArtifact = EdgeArtifact::query()->where('edge_id', $id)->where('domain_id', $domain->id)->latest('sequence')->firstOrFail();
        $this->assertGreaterThan($moveArtifact->sequence, $drainedArtifact->sequence);
        $this->withHeaders($identity)->postJson('/edge/v1/config/applied', ['sequence' => $drainedArtifact->sequence])->assertOk();
        $this->assertDatabaseHas('domain_edge_placements', ['domain_id' => $domain->id, 'active_pool_id' => $pool, 'target_pool_id' => null, 'state' => 'active']);
        $this->assertSame(['dedicated-test'], $drainedArtifact->payload['pools']);

        $this->actingAs($admin)->postJson("/api/admin/edges/$id/rotate-identity")->assertOk();
        $this->withHeaders($identity)->getJson('/edge/v1/config/manifest?cursor=0')->assertUnauthorized();
        $this->assertNotNull(Edge::query()->findOrFail($id)->identity_revoked_at);
    }

    public function test_origin_test_tasks_and_phase_four_operations_are_visible_to_administrators(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $created = $this->actingAs($admin)->postJson('/api/admin/edges', ['name' => 'edge-test', 'country_code' => 'IR', 'continent_code' => 'AS', 'ipv4' => '203.0.113.20'])
            ->assertCreated();
        $edgeId = $created->json('data.id');
        $registered = $this->postJson('/edge/v1/register', ['edge_id' => $edgeId, 'bootstrap_token' => $created->json('data.bootstrap_token'), 'agent_version' => '1.0.0', 'certificate_request' => $this->certificateRequest($edgeId)])->assertCreated();
        $identity = $this->edgeIdentityHeaders($registered->json('data.identity_certificate_serial'));
        $this->withHeaders($identity)->postJson('/edge/v1/heartbeat', ['agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => 0, 'cells' => [
            ['name' => 'shared-default', 'status' => 'ready', 'capacity' => ['active_connections' => 0]],
        ]])->assertOk();

        [$user, $domain] = $this->ownedDomain();
        $record = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('www', '8.8.8.8'))->assertCreated()->json('data.id');
        $scheduledOrigin = $domain->dnsRecords()->findOrFail($record)->origin;
        $scheduledOrigin['health_check'] = ['enabled' => true, 'path' => '/', 'interval_seconds' => 60];
        $domain->dnsRecords()->whereKey($record)->update(['origin' => $scheduledOrigin, 'created_at' => now()->subHour()]);
        $this->artisan('edge:dispatch-origin-checks', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseHas('operations', ['type' => 'edge.origin_test', 'status' => 'running']);
        $this->assertSame(1, Operation::query()->where('input->scheduled', true)->count());
        $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records/$record/origin/test", [])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.operation_id'));
        $this->assertSame('running', $operation->status);
        $task = EdgeTask::query()->where('payload->operation_id', $operation->id)->firstOrFail();
        $this->withHeaders($identity)->postJson("/edge/v1/tasks/{$task->id}/result", ['status' => 'succeeded', 'result' => [
            'status' => 'healthy', 'latency_ms' => 12, 'resolved_address' => '8.8.8.8', 'tls_result' => null, 'http_status' => 200, 'failure_reason' => null,
        ]])->assertOk();
        $this->assertSame('succeeded', $operation->refresh()->status);

        $this->actingAs($admin)->get('/admin/operations')->assertOk()
            ->assertSee('Edge domain deployment')->assertSee('Edge origin test');
    }

    private function record(string $name, string $host): array
    {
        return ['type' => 'A', 'name' => $name, 'content' => $host, 'ttl' => 60, 'mode' => 'proxied', 'origin' => $this->origin($host)];
    }

    private function certificateRequest(string $edgeId): string
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $request = openssl_csr_new(['commonName' => $edgeId], $privateKey, ['digest_alg' => 'sha256']);
        $this->assertTrue(openssl_csr_export($request, $pem));

        return $pem;
    }

    private function edgeIdentityHeaders(string $serial): array
    {
        return ['X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $serial];
    }

    private function origin(string $host): array
    {
        return ['host' => $host, 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 1];
    }

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => uniqid('d').'.example', 'display_name' => 'Example', 'revision' => 1]);
        $domain->users()->attach($user);

        return [$user, $domain];
    }
}
