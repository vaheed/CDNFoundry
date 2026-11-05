<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmergencyMode;
use App\Models\Operation;
use App\Models\User;
use App\Support\ControlPlaneMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
}
