<?php

namespace Tests\Feature;

use App\Jobs\DispatchOriginTest;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\OriginHealth;
use App\Support\OriginMonitoring;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OriginMonitoringPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $health = ['enabled' => true, 'path' => '/health', 'interval_seconds' => 60]): DnsRecord
    {
        $domain = Domain::query()->create(['name' => 'policy-'.Str::random(6).'.example', 'display_name' => 'P', 'lifecycle_state' => 'active', 'revision' => 1, 'nameservers_verified_at' => now()]);

        return $domain->dnsRecords()->create(['name' => $domain->name, 'type' => 'A', 'content' => '8.8.8.8', 'content_hash' => hash('sha256', '8.8.8.8'), 'mode' => 'proxied', 'ttl' => 60,
            'origin' => ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => $domain->name, 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => $health]]);
    }

    public function test_a_new_installation_has_monitoring_off_and_an_upgrade_with_a_scheduled_check_keeps_it_on(): void
    {
        $this->assertFalse(OriginMonitoring::enabled());
        $this->record(['enabled' => false, 'path' => '/', 'interval_seconds' => 60]);
        SystemSetting::query()->where('group', 'origin_monitoring')->delete();
        (require base_path('database/migrations/2026_12_01_000300_add_origin_monitoring_setting.php'))->up();
        app()->forgetInstance(PlatformSettings::class);
        $this->assertFalse(OriginMonitoring::enabled(), 'no origin has a scheduled check');

        $this->record();
        SystemSetting::query()->where('group', 'origin_monitoring')->delete();
        (require base_path('database/migrations/2026_12_01_000300_add_origin_monitoring_setting.php'))->up();
        app()->forgetInstance(PlatformSettings::class);
        $this->assertTrue(OriginMonitoring::enabled(), 'an installation that already schedules checks keeps them on');
    }

    public function test_the_scheduler_does_nothing_while_monitoring_is_off_and_keeps_the_platform_floor_when_on(): void
    {
        Queue::fake();
        $record = $this->record(['enabled' => true, 'path' => '/h', 'interval_seconds' => 60]);
        Artisan::call('cdnf:edge:dispatch-origin-checks');
        Queue::assertNotPushed(DispatchOriginTest::class);
        $this->assertSame(0, Operation::query()->where('type', 'edge.origin_test')->count());

        $this->enableOriginMonitoring();
        $this->assertSame(60, OriginMonitoring::interval(30));
        $this->assertSame(300, OriginMonitoring::interval(300));
        $row = SystemSetting::query()->findOrFail('origin_monitoring');
        $row->update(['values' => [...$row->values, 'minimum_interval_seconds' => 30]]);
        app()->forgetInstance(PlatformSettings::class);
        $this->assertSame(30, OriginMonitoring::interval(30));
        DB::table('dns_records')->where('id', $record->id)->update(['created_at' => now()->subHour()]);
        Artisan::call('cdnf:edge:dispatch-origin-checks');
        $this->assertSame(1, Operation::query()->where('type', 'edge.origin_test')->count(), 'one scheduled check once the platform allows it');
    }

    public function test_a_manual_test_is_refused_while_monitoring_is_off_unless_the_administrator_allows_it(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $record = $this->record();
        $record->domain->users()->attach($user->id);
        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/domains/{$record->domain_id}/dns/records/{$record->id}/origin/test", [])->assertStatus(409)
            ->assertSee('manual origin tests are not available');
        $row = SystemSetting::query()->findOrFail('origin_monitoring');
        $row->update(['values' => [...$row->values, 'manual_tests_when_off' => true]]);
        app()->forgetInstance(PlatformSettings::class);
        Queue::fake();
        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/domains/{$record->domain_id}/dns/records/{$record->id}/origin/test", [])->assertStatus(202);
    }

    public function test_turning_monitoring_off_stops_queued_checks_and_reads_the_active_result_as_disabled_without_touching_passive_evidence(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->enableOriginMonitoring();
        $record = $this->record();
        $edge = Edge::query()->create(['name' => 'probe-edge', 'country_code' => 'IR', 'continent_code' => 'AS']);
        $operation = Operation::query()->create(['type' => 'edge.origin_test', 'status' => 'running', 'input' => ['domain_id' => $record->domain_id, 'record_id' => $record->id, 'scheduled' => true]]);
        $task = EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'type' => 'origin_test', 'status' => 'pending', 'payload' => ['operation_id' => $operation->id]]);
        OriginHealth::record($record->refresh(), 'primary', 'active', ['status' => 'healthy', 'observer' => 'edge']);
        OriginHealth::record($record->refresh(), 'primary', 'passive', ['status' => 'unhealthy', 'failure_count' => 3]);
        $this->assertSame('degraded', OriginHealth::summarize($record->refresh())['state']);

        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/origin_monitoring', ['values' => ['enabled' => false]])->assertSuccessful();

        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame('failed', $task->refresh()->status);
        $this->assertTrue($task->result['cancelled']);
        $summary = OriginHealth::summarize($record->refresh());
        $this->assertSame('disabled', $summary['roles']['primary']['signals']['active']['state']);
        $this->assertSame('unhealthy', $summary['roles']['primary']['signals']['passive']['state'], 'passive evidence is kept');
        Queue::assertNotPushed(DispatchOriginTest::class);
    }
}
