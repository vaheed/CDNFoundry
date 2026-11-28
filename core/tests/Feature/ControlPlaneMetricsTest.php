<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\EmergencyMode;
use App\Models\Operation;
use App\Models\User;
use App\Support\ControlPlaneMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ControlPlaneMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function scrape(): string
    {
        config(['services.metrics.token' => 'metrics-test-token']);

        return $this->withToken('metrics-test-token')->get('/metrics')->assertOk()->getContent();
    }

    public function test_a_failed_operation_counts_only_inside_the_failure_window(): void
    {
        Operation::query()->create(['type' => 'dns.reconcile', 'status' => 'failed', 'input' => [], 'finished_at' => now()->subMinutes(10)]);
        $old = Operation::query()->create(['type' => 'dns.reconcile', 'status' => 'failed', 'input' => [], 'finished_at' => now()->subHours(5)]);
        Operation::query()->whereKey($old->id)->update(['updated_at' => now()->subHours(5)]);

        $this->assertStringContainsString("cdnfoundry_operations_failed_recent 1\n", $this->scrape());

        $this->travel(2)->hours();

        $this->assertStringContainsString("cdnfoundry_operations_failed_recent 0\n", $this->scrape());
    }

    public function test_request_latency_is_a_histogram_per_route_pattern_with_bounded_labels(): void
    {
        Cache::flush();
        ControlPlaneMetrics::recordRequest('api/domains/{domain}', 200, 0.04);
        ControlPlaneMetrics::recordRequest('api/domains/{domain}', 503, 3.0);
        ControlPlaneMetrics::recordRequest(null, 404, 0.01);

        $body = $this->scrape();

        $this->assertStringContainsString('cdnfoundry_http_requests_total{route="/api/domains/{domain}",status_class="5xx"} 1', $body);
        $this->assertStringContainsString('cdnfoundry_http_request_duration_seconds_bucket{route="/api/domains/{domain}",le="0.05"} 1', $body);
        $this->assertStringContainsString('cdnfoundry_http_request_duration_seconds_bucket{route="/api/domains/{domain}",le="+Inf"} 2', $body);
        $this->assertStringContainsString('cdnfoundry_http_request_duration_seconds_count{route="/api/domains/{domain}"} 2', $body);
        $this->assertStringContainsString('route="unmatched"', $body);
    }

    public function test_distinct_route_labels_stop_at_the_cap(): void
    {
        Cache::flush();
        foreach (range(1, 230) as $number) {
            ControlPlaneMetrics::recordRequest("probe/{$number}", 200, 0.01);
        }

        $routes = collect(explode("\n", $this->scrape()))->filter(fn (string $line): bool => str_starts_with($line, 'cdnfoundry_http_requests_total{'))->count();

        $this->assertLessThanOrEqual(201, $routes);
        $this->assertStringContainsString('route="other"', $this->scrape());
    }

    public function test_logins_and_token_use_are_counted_by_result_and_ability(): void
    {
        Cache::flush();
        $user = User::factory()->create(['password' => 'correct-horse-Battery9']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        ControlPlaneMetrics::recordTokenUse(['read', 'secret-ability', '*']);

        $body = $this->scrape();

        $this->assertStringContainsString('cdnfoundry_logins_total{result="failure",surface=', $body);
        $this->assertStringContainsString('cdnfoundry_api_token_requests_total{ability="read"} 1', $body);
        $this->assertStringContainsString('cdnfoundry_api_token_requests_total{ability="other"} 1', $body);
        $this->assertStringContainsString('cdnfoundry_api_token_requests_total{ability="full"} 1', $body);
        $this->assertStringNotContainsString('secret-ability', $body);
    }

    public function test_propagation_gauges_are_present_and_zero_when_nothing_is_pending(): void
    {
        $body = $this->scrape();

        foreach (['cdnfoundry_purge_pending_oldest_seconds', 'cdnfoundry_config_pending_oldest_seconds', 'cdnfoundry_dns_serial_pending_oldest_seconds', 'cdnfoundry_acme_issuance_seconds_max'] as $metric) {
            $this->assertStringContainsString("{$metric} 0\n", $body);
        }
        $this->assertStringContainsString('cdnfoundry_acme_orders_day{status="failed"} 0', $body);
    }

    public function test_an_administrator_signing_in_from_an_unseen_network_is_counted_once(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        AuditLog::record($admin, 'auth.login', $admin, [], '203.0.113.10');
        AuditLog::query()->update(['created_at' => now()->subDays(3)]);
        AuditLog::record($admin, 'auth.login', $admin, [], '203.0.113.200');
        AuditLog::record($admin, 'auth.login', $admin, [], '198.51.100.7');
        AuditLog::record($admin, 'auth.login', $admin, [], '2001:db8:aaaa:1::5');

        $this->assertStringContainsString("cdnfoundry_admin_logins_new_network_recent 2\n", $this->scrape());
    }

    public function test_active_emergency_modes_are_counted_until_they_expire(): void
    {
        EmergencyMode::query()->create(['target_type' => 'global', 'target_id' => 'all', 'active' => true, 'actions' => [], 'expires_at' => now()->addHour()]);
        EmergencyMode::query()->create(['target_type' => 'global', 'target_id' => 'all', 'active' => true, 'actions' => [], 'expires_at' => now()->subMinute()]);

        $this->assertStringContainsString("cdnfoundry_emergency_modes_active 1\n", $this->scrape());
    }

    public function test_the_status_summary_names_what_is_wrong(): void
    {
        $body = $this->scrape();

        $this->assertMatchesRegularExpression('/cdnfoundry_status_summary\{state="degraded",summary="Degraded: \d+ components? unhealthy[^"]*"\} 1/', $body);
    }

    public function test_origin_monitoring_and_usage_coverage_are_reported_with_fixed_labels_and_no_invented_zero(): void
    {
        $body = $this->scrape();
        $this->assertStringContainsString("cdnfoundry_origin_monitoring_enabled 0\n", $body, 'a new installation has monitoring off');
        $this->assertStringContainsString("cdnfoundry_origin_probe_oldest_pending_seconds 0\n", $body);
        $this->assertStringContainsString("cdnfoundry_usage_incomplete_hours 0\n", $body);
        $this->assertStringNotContainsString('cdnfoundry_usage_coverage_age_seconds', $body, 'with no built hour there is no age to report, and none is invented');

        $this->enableOriginMonitoring();
        $edge = Edge::query()->create(['name' => 'metrics-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true]);
        $task = EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'type' => 'origin_test', 'status' => 'pending', 'payload' => ['domain_id' => 1, 'destination' => '1.1.1.1:80']]);
        EdgeTask::query()->whereKey($task->id)->update(['created_at' => now()->subMinutes(7)]);
        DB::table('usage_coverage')->insert(['interval_start' => now()->subHours(3)->startOfHour(), 'interval_end' => now()->subHours(2)->startOfHour(), 'status' => 'incomplete', 'reason' => 'no_telemetry', 'domains_with_traffic' => 0, 'built_at' => now()]);

        $body = $this->scrape();
        $this->assertStringContainsString("cdnfoundry_origin_monitoring_enabled 1\n", $body);
        $this->assertStringContainsString('cdnfoundry_origin_probe_tasks{status="pending"} 1', $body);
        preg_match('/cdnfoundry_origin_probe_oldest_pending_seconds (\d+)\n/', $body, $waiting);
        $this->assertGreaterThanOrEqual(400, (int) $waiting[1], 'the oldest waiting probe was created seven minutes ago');
        $this->assertLessThan(3600, (int) $waiting[1]);
        $this->assertStringContainsString("cdnfoundry_usage_incomplete_hours 1\n", $body);
        preg_match('/cdnfoundry_usage_coverage_age_seconds (\d+)\n/', $body, $age);
        $this->assertGreaterThanOrEqual(7000, (int) $age[1], 'the newest built hour ended about two hours ago');
        $this->assertLessThan(86400, (int) $age[1]);
        $this->assertDoesNotMatchRegularExpression('/cdnfoundry_(origin|usage)_[a-z_]*\{[^}]*(domain|record|client|request)/', $body, 'no domain, record, client or request label');
    }
}
