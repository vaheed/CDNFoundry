<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\TlsOrder;
use App\Models\User;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Failed certificate orders and failed edge tasks are classified by cause, the same way DNS deployments are: a
 * customer's CAA record or origin is customer health, anything else, including anything unclassified, is the platform's.
 */
class CustomerHealthSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_the_order_classification_by_cause(): void
    {
        $healthy = new Domain;
        $lost = new Domain;
        $lost->delegation_lost_at = now();

        $this->assertSame('customer', TlsOrder::failureClassFor(new \RuntimeException('CAA record for example.test prevents issuance'), $healthy));
        $this->assertSame('customer', TlsOrder::failureClassFor(new \RuntimeException('urn:ietf:params:acme:error:rejectedIdentifier'), $healthy));
        $this->assertSame('customer', TlsOrder::failureClassFor(new \RuntimeException('ACME DNS validation failed'), $lost));
        $this->assertSame('platform', TlsOrder::failureClassFor(new \RuntimeException('ACME DNS validation failed: x'), $healthy));
        $this->assertSame('platform', TlsOrder::failureClassFor(null, null));
        $this->assertSame('platform', TlsOrder::failureClassFor(new \RuntimeException('Connection timed out'), $healthy));
    }

    public function test_customer_failed_orders_and_origin_tests_leave_platform_health_untouched_and_show_as_customer_health(): void
    {
        $domain = $this->domain('customer.example');
        $this->order($domain, 'customer');
        $this->order($domain, 'customer');
        $this->task('origin_test', 'failed');
        $this->task('origin_test', 'failed');
        $health = app(SystemHealth::class);

        $components = $health->components();

        $this->assertSame('healthy', $components['tls']['status']);
        $this->assertSame('healthy', $components['runtime_tasks']['status']);
        $this->assertSame(0, $components['tls']['details']['failed_orders']);
        $this->assertSame(['tls_orders_failed' => 2, 'origin_tests_failed' => 2], array_intersect_key($health->customerHealth(), ['tls_orders_failed' => 0, 'origin_tests_failed' => 0]));
        $public = $health->publicSnapshot($components, $health->queues());
        $this->assertStringNotContainsString('customer.example', json_encode($public));
    }

    public function test_platform_and_unclassified_failures_degrade_platform_health(): void
    {
        $domain = $this->domain('platform.example');
        $this->order($domain, 'platform');
        $health = app(SystemHealth::class);
        $this->assertSame('degraded', $health->components()['tls']['status']);

        TlsOrder::query()->delete();
        $this->order($domain, null);
        $this->assertSame('degraded', $health->components()['tls']['status']);

        TlsOrder::query()->delete();
        $this->assertSame('healthy', $health->components()['tls']['status']);
        $this->task('cache_purge', 'failed');
        $this->assertSame('degraded', $health->components()['runtime_tasks']['status']);
    }

    public function test_a_surge_of_customer_class_order_failures_is_a_platform_fault(): void
    {
        $domain = $this->domain('surge.example');
        for ($index = 0; $index < TlsOrder::CUSTOMER_FAILURE_SURGE; $index++) {
            $this->order($domain, 'customer');
        }

        $this->assertSame('degraded', app(SystemHealth::class)->components()['tls']['status']);
    }

    public function test_many_unverified_domains_and_old_origin_test_failures_change_nothing_on_the_platform(): void
    {
        for ($index = 0; $index < 30; $index++) {
            $this->domain("pending-{$index}.example");
        }
        $old = $this->task('origin_test', 'failed');
        $old->forceFill(['finished_at' => now()->subDays(3)])->saveQuietly();
        $health = app(SystemHealth::class);

        $customer = $health->customerHealth();

        $this->assertSame(30, $customer['domains_pending_delegation']);
        $this->assertSame(0, $customer['origin_tests_failed']);
        $this->assertSame('healthy', $health->components()['runtime_tasks']['status']);
    }

    public function test_dimensions_name_what_a_component_answers_and_an_analytics_outage_never_reports_serving_down(): void
    {
        $health = app(SystemHealth::class);
        $components = [
            'edges' => ['status' => 'healthy'], 'authoritative_dns' => ['status' => 'healthy'],
            'clickhouse' => ['status' => 'unavailable'], 'vector' => ['status' => 'degraded'], 'control_database' => ['status' => 'healthy'],
        ];

        $dimensions = $health->dimensions($components);

        $this->assertSame('healthy', $dimensions['serving']['status']);
        $this->assertSame('unavailable', $dimensions['observability']['status']);
        $this->assertSame('healthy', $dimensions['management']['status']);
        $this->assertSame('insufficient_evidence', $health->dimensions([])['serving']['status']);
        $this->assertSame('insufficient_evidence', $health->dimensions(['edges' => ['status' => 'unknown']])['serving']['status']);
    }

    public function test_the_admin_health_api_and_metrics_carry_dimensions_and_customer_conditions_for_administrators_only(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->domain('api.example');

        $this->actingAs($user)->getJson('/api/admin/system/components')->assertForbidden();
        $response = $this->actingAs($admin)->getJson('/api/admin/system/components')->assertOk();
        $this->assertSame(1, $response->json('data.customer.domains_pending_delegation'));
        $this->assertArrayHasKey('serving', $response->json('data.dimensions'));
        $this->assertArrayHasKey('observability', $response->json('data.dimensions'));
    }

    private function domain(string $name): Domain
    {
        return Domain::query()->create(['name' => $name, 'display_name' => $name, 'lifecycle_state' => 'pending_verification', 'revision' => 1]);
    }

    private function order(Domain $domain, ?string $class): TlsOrder
    {
        return TlsOrder::query()->create(['domain_id' => $domain->id, 'status' => 'failed', 'names' => [$domain->name], 'names_hash' => hash('sha256', (string) microtime()),
            'failure_class' => $class, 'finished_at' => now(), 'last_error' => 'failed']);
    }

    private function task(string $type, string $status): EdgeTask
    {
        $edge = Edge::query()->first() ?? Edge::query()->create(['name' => 'edge-x', 'country_code' => 'DE', 'continent_code' => 'EU']);

        return EdgeTask::query()->create(['edge_id' => $edge->id, 'type' => $type, 'status' => $status, 'payload' => [], 'finished_at' => now()]);
    }
}
