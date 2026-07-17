<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeRevision;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Models\User;
use App\Support\ArtifactSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeProxyTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertDatabaseHas('operations', ['type' => 'edge.domain_reconcile', 'actor_id' => $user->id, 'status' => 'succeeded']);

        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/dns/records/$first/origin", array_merge($this->origin('9.9.9.9'), ['host' => '127.0.0.1']))->assertUnprocessable();
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/dns/records/$first/origin", $this->origin('9.9.9.9'))->assertAccepted();
        $this->assertSame('9.9.9.9', $domain->dnsRecords()->find($first)->origin['host']);
    }

    public function test_edge_bootstrap_is_one_time_and_artifacts_require_active_identity(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $created = $this->actingAs($admin)->postJson('/api/admin/edges', ['name' => 'edge-ir-1', 'country_code' => 'IR', 'continent_code' => 'AS', 'ipv4' => '203.0.113.10', 'ipv6' => '2001:db8::10'])
            ->assertCreated();
        $id = $created->json('data.id');
        $bootstrap = $created->json('data.bootstrap_token');
        $registered = $this->postJson('/edge/v1/register', ['edge_id' => $id, 'bootstrap_token' => $bootstrap, 'agent_version' => '1.0.0'])->assertCreated();
        $identity = $registered->json('data.identity_token');
        $this->postJson('/edge/v1/register', ['edge_id' => $id, 'bootstrap_token' => $bootstrap, 'agent_version' => '1.0.0'])->assertUnauthorized();
        $this->withToken($identity)->postJson('/edge/v1/heartbeat', ['agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => 0, 'cells' => [
            ['name' => 'pool-1', 'status' => 'ready', 'capacity' => ['active_connections' => 0, 'memory_usage' => 0]],
        ]])->assertOk();

        [$user, $domain] = $this->ownedDomain();
        $record = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $this->record('www', '8.8.8.8'))->assertCreated()->json('data.id');
        $artifact = EdgeArtifact::query()->where('edge_id', $id)->firstOrFail();
        $this->assertTrue(sodium_crypto_sign_verify_detached(hex2bin($artifact->signature), $artifact->checksum, hex2bin($registered->json('data.signing_public_key'))));
        $this->withToken($identity)->getJson("/edge/v1/config/artifacts/{$artifact->checksum}")->assertOk()
            ->assertJsonPath('encoded_payload', base64_encode(ArtifactSigner::encode($artifact->payload)));
        $this->withToken($identity)->getJson('/edge/v1/config/manifest?cursor=0')->assertOk()->assertJsonPath('data.0.checksum', $artifact->checksum);
        $full = $this->withToken($identity)->getJson('/edge/v1/config/full')->assertOk()->assertJsonPath('data.artifacts.0.revision', $domain->refresh()->revision);
        $this->assertTrue(sodium_crypto_sign_verify_detached(hex2bin($full->json('signature')), $full->json('checksum'), hex2bin($full->json('signing_public_key'))));
        $this->withToken($identity)->postJson('/edge/v1/config/applied', ['sequence' => $artifact->sequence + 100])->assertUnprocessable();
        $this->withToken($identity)->postJson('/edge/v1/config/applied', ['sequence' => $artifact->sequence])->assertOk();
        $this->assertDatabaseHas('domain_edge_placements', ['domain_id' => $domain->id, 'state' => 'active', 'desired_revision' => $domain->revision]);
        $this->assertSame($domain->revision, $domain->refresh()->active_edge_revision);
        $artifactCount = EdgeArtifact::query()->where('domain_id', $domain->id)->count();
        $this->withToken($identity)->postJson('/edge/v1/heartbeat', ['agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => $artifact->sequence, 'cells' => [
            ['name' => 'pool-1', 'status' => 'ready', 'capacity' => ['active_connections' => 1]],
        ]])->assertOk();
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
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/dns/records/$record/origin", $this->origin('9.9.9.9'))->assertAccepted();
        $beforeRollback = $domain->refresh()->revision;
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => $validatedRevision])->assertAccepted();
        $this->assertSame($beforeRollback + 1, $domain->refresh()->revision);
        $this->assertSame('8.8.8.8', $domain->dnsRecords()->findOrFail($record)->origin['host']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'proxy.revision_rolled_back', 'subject_id' => (string) $domain->id]);

        $pool = $this->actingAs($admin)->postJson('/api/admin/edge-pools', ['name' => 'dedicated-test', 'kind' => 'dedicated'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/move", ['pool_id' => $pool])->assertAccepted();
        $moveArtifact = EdgeArtifact::query()->where('edge_id', $id)->where('domain_id', $domain->id)->latest('sequence')->firstOrFail();
        $this->withToken($identity)->postJson('/edge/v1/config/applied', ['sequence' => $moveArtifact->sequence])->assertOk();
        $this->assertDatabaseHas('domain_edge_placements', ['domain_id' => $domain->id, 'active_pool_id' => 1, 'target_pool_id' => $pool, 'state' => 'draining']);
        DomainEdgePlacement::query()->where('domain_id', $domain->id)->update(['drain_after' => now()->subSecond()]);
        $this->artisan('edge:complete-placement-drains')->assertSuccessful();
        $this->assertDatabaseHas('domain_edge_placements', ['domain_id' => $domain->id, 'active_pool_id' => $pool, 'target_pool_id' => null, 'state' => 'active']);

        $this->actingAs($admin)->postJson("/api/admin/edges/$id/rotate-identity")->assertOk();
        $this->withToken($identity)->getJson('/edge/v1/config/manifest?cursor=0')->assertUnauthorized();
        $this->assertNotNull(Edge::query()->findOrFail($id)->identity_revoked_at);
    }

    public function test_origin_test_tasks_and_phase_four_operations_are_visible_to_administrators(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $created = $this->actingAs($admin)->postJson('/api/admin/edges', ['name' => 'edge-test', 'country_code' => 'IR', 'continent_code' => 'AS', 'ipv4' => '203.0.113.20'])
            ->assertCreated();
        $registered = $this->postJson('/edge/v1/register', ['edge_id' => $created->json('data.id'), 'bootstrap_token' => $created->json('data.bootstrap_token'), 'agent_version' => '1.0.0'])->assertCreated();
        $identity = $registered->json('data.identity_token');
        $this->withToken($identity)->postJson('/edge/v1/heartbeat', ['agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => 0, 'cells' => [
            ['name' => 'pool-1', 'status' => 'ready', 'capacity' => ['active_connections' => 0]],
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
        $this->withToken($identity)->postJson("/edge/v1/tasks/{$task->id}/result", ['status' => 'succeeded', 'result' => [
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
