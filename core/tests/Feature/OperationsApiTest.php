<?php

namespace Tests\Feature;

use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileAllPurges;
use App\Jobs\ReconcileAllTls;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\EmergencyMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_component_health_is_admin_only_and_uses_stable_states(): void
    {
        Http::fake(['*' => Http::response('Ok.', 200)]);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/admin/system/components')->assertForbidden();
        $response = $this->actingAs($admin)->getJson('/api/admin/system/components')->assertOk();
        $this->assertContains($response->json('data.status'), ['healthy', 'degraded', 'unavailable']);
        $this->assertContains($response->json('data.components.control_database.status'), ['healthy', 'degraded', 'unavailable']);
        foreach (['queue_workers', 'mmdb', 'edge_listeners', 'edge_cells', 'service_pools', 'edge_configuration', 'edge_placements', 'edge_capacity', 'emergency_modes', 'dns_deployments', 'purges'] as $component) {
            $this->assertContains($response->json("data.components.{$component}.status"), ['healthy', 'degraded', 'unavailable']);
        }
        $this->assertSame(['interactive', 'runtime', 'certificate_purge', 'bulk_maintenance'], array_keys($response->json('data.queues')));
    }

    public function test_component_health_detects_runtime_drift_pressure_and_stale_mmdb(): void
    {
        Http::fake(['*' => Http::response('Ok.', 200)]);
        $mmdb = tempnam(sys_get_temp_dir(), 'cdnf-mmdb-health-');
        file_put_contents($mmdb, 'qualified-mmdb-placeholder');
        touch($mmdb, now()->subHours(49)->timestamp);
        config(['services.geoip.database' => $mmdb, 'services.geoip.stale_hours' => 48]);

        try {
            $admin = User::factory()->admin()->create();
            $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
            $edge = Edge::query()->create([
                'id' => (string) Str::uuid(), 'name' => 'health-edge', 'country_code' => 'US', 'continent_code' => 'NA',
                'ipv4' => '192.0.2.80', 'ipv6' => '2001:db8::80', 'enabled' => true, 'drained' => false,
                'registered_at' => now(), 'last_heartbeat_at' => now(), 'agent_version' => '1.1.0',
                'capacity' => ['listener_ready' => false, 'last_rejection' => ['reason' => 'candidate_validation_failed']],
            ]);
            EdgeCell::query()->create([
                'edge_id' => $edge->id, 'edge_pool_id' => $pool->id, 'name' => 'shared-health',
                'status' => 'degraded', 'capacity' => ['memory_usage' => 90, 'memory_limit' => 100],
            ]);
            $domain = Domain::query()->create([
                'name' => 'health-drift.example', 'display_name' => 'Health drift', 'lifecycle_state' => 'active',
                'revision' => 2, 'active_edge_revision' => 1,
            ]);
            DomainEdgePlacement::query()->create([
                'domain_id' => $domain->id, 'active_pool_id' => $pool->id, 'state' => 'active', 'desired_revision' => 2,
            ]);
            EmergencyMode::query()->create([
                'id' => (string) Str::uuid(), 'target_type' => 'edge', 'target_id' => $edge->id,
                'actions' => ['allow_get_head_only'], 'active' => true, 'created_by' => $admin->id,
            ]);

            $response = $this->actingAs($admin)->getJson('/api/admin/system/components')->assertOk();
            foreach (['mmdb', 'edge_listeners', 'edge_cells', 'service_pools', 'edge_configuration', 'edge_placements', 'edge_capacity', 'emergency_modes'] as $component) {
                $response->assertJsonPath("data.components.{$component}.status", 'degraded');
            }
            $response->assertJsonPath('data.components.edge_capacity.details.pressured_cells', 1)
                ->assertJsonPath('data.components.edge_placements.details.drifted', 1)
                ->assertJsonPath('data.components.emergency_modes.details.active', 1);
        } finally {
            @unlink($mmdb);
        }
    }

    public function test_clock_offset_beyond_database_threshold_is_degraded(): void
    {
        Http::fake([
            'http://prometheus:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['result' => [['value' => [now()->timestamp, '6.25']]]]]),
            '*' => Http::response('Ok.', 200),
        ]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/admin/system/components')->assertOk()
            ->assertJsonPath('data.components.host_clock.status', 'degraded')
            ->assertJsonPath('data.components.host_clock.details.maximum_offset_seconds', 6.25)
            ->assertJsonPath('data.components.host_clock.details.warning_seconds', 5);
    }

    public function test_failed_jobs_are_bounded_redacted_audited_and_deletable(): void
    {
        $admin = User::factory()->admin()->create();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'runtime',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Example', 'data' => ['command' => 'sensitive serialized body']], JSON_THROW_ON_ERROR),
            'exception' => "RuntimeException: safe summary\nstack with internals", 'failed_at' => now(),
        ]);
        $job = DB::table('failed_jobs')->first();
        $this->actingAs($admin)->getJson('/api/admin/jobs/failed')->assertOk()
            ->assertJsonPath('data.0.job', 'App\\Jobs\\Example')->assertJsonMissing(['sensitive serialized body']);
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->deleteJson("/api/admin/jobs/failed/{$job->uuid}")->assertNoContent();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $job->uuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'failed_job.deleted']);
    }

    public function test_reconciliation_is_coalesced_and_dispatched_to_bounded_lanes(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        foreach (['dns' => ReconcileAllDnsZones::class, 'edges' => ReconcileAllEdgeDomains::class, 'tls' => ReconcileAllTls::class, 'purges' => ReconcileAllPurges::class] as $scope => $jobClass) {
            $key = (string) Str::uuid();
            $first = $this->actingAs($admin)->withHeader('Idempotency-Key', $key)->postJson("/api/admin/reconcile/{$scope}")->assertAccepted();
            $this->actingAs($admin)->withHeader('Idempotency-Key', $key)->postJson("/api/admin/reconcile/{$scope}")->assertAccepted()->assertJsonPath('data.operation_id', $first->json('data.operation_id'));
            Queue::assertPushed($jobClass, 1);
        }
        $this->assertSame(4, AuditLog::query()->where('action', 'like', '%.global_reconcile_requested')->count());
    }

    public function test_metrics_require_a_separate_bearer_token_and_never_expose_secrets(): void
    {
        Http::fake(['*' => Http::response('Ok.', 200)]);
        config(['services.metrics.token' => 'metrics-test-token']);
        $this->get('/metrics')->assertNotFound();
        $response = $this->withToken('metrics-test-token')->get('/metrics')->assertOk()->assertHeader('content-type', 'text/plain; version=0.0.4; charset=utf-8');
        $response->assertSee('cdnfoundry_component_health')->assertDontSee((string) config('app.key'));
    }

    public function test_audit_pruning_is_bounded_and_uses_database_policy(): void
    {
        $old = User::factory()->create();
        AuditLog::record($old, 'old.event');
        AuditLog::query()->update(['created_at' => now()->subDays(400)]);
        AuditLog::record($old, 'current.event');
        $this->artisan('audit:prune', ['--batch' => 1])->assertSuccessful();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'old.event']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'current.event']);
    }
}
