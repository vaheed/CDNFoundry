<?php

namespace Tests\Feature;

use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_does_not_require_authentication(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_browser_health_link_shows_public_snapshot_and_keeps_json_liveness(): void
    {
        $this->withHeader('Accept', 'text/html')->get('/api/health')->assertRedirect('/health');
        $this->withHeader('Accept', 'text/html')->get('/api/health?format=json')->assertOk()->assertExactJson(['status' => 'ok']);

        $this->get('/health')->assertOk()->assertSee('Status unavailable')->assertSee('No operational snapshot is available yet');

        Cache::put(SystemHealth::PUBLIC_CACHE_KEY, [
            'status' => 'degraded', 'checked_at' => now()->toIso8601String(),
            'groups' => [[
                'key' => 'dns', 'label' => 'DNS', 'description' => 'Authoritative service and zone delivery',
                'status' => 'degraded', 'checks' => [['name' => 'Nameservers', 'status' => 'degraded']],
            ]],
        ], now()->addMinutes(10));
        $this->get('/health')->assertOk()->assertSee('Some systems degraded')->assertSee('Nameservers');

        $this->travel(3)->minutes();
        $this->get('/health')->assertOk()->assertSee('Status unavailable')->assertSee('historical');
    }

    public function test_public_health_snapshot_exposes_only_safe_states(): void
    {
        $health = app(SystemHealth::class);
        $snapshot = $health->publicSnapshot([
            'authoritative_dns' => ['status' => 'degraded', 'details' => ['api_key' => 'never-public']],
            'control_database' => ['status' => 'healthy', 'details' => ['host' => 'private-db']],
        ], ['interactive' => ['status' => 'unavailable', 'depth' => 123]]);

        $this->assertSame('degraded', $snapshot['groups'][0]['status']);
        $this->assertSame('outage', $snapshot['status']);
        $this->assertStringNotContainsString('never-public', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-db', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('123', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_scheduler_command_publishes_a_bounded_status_snapshot(): void
    {
        Http::fake(['*' => Http::response('Ok.', 200)]);

        $this->artisan('cdnf:health:publish')->assertExitCode(0);
        $snapshot = Cache::get(SystemHealth::PUBLIC_CACHE_KEY);

        $this->assertCount(7, $snapshot['groups']);
        $this->assertArrayHasKey('checked_at', $snapshot);
        $this->assertArrayNotHasKey('details', $snapshot['groups'][0]['checks'][0]);
    }

    public function test_public_status_page_degrades_without_exposing_cache_errors(): void
    {
        Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('private-cache-address'));

        $this->get('/health')->assertOk()->assertSee('Status unavailable')->assertDontSee('private-cache-address');
    }

    public function test_request_id_is_accepted_when_bounded_and_generated_when_invalid(): void
    {
        $this->withHeader('X-Request-ID', 'edge-sync:abc-123')
            ->getJson('/api/health')
            ->assertHeader('X-Request-ID', 'edge-sync:abc-123');

        $response = $this->withHeader('X-Request-ID', str_repeat('x', 97))->getJson('/api/health');
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', (string) $response->headers->get('X-Request-ID'));

        $this->withHeader('X-Request-ID', 'failed-request-123')
            ->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('X-Request-ID', 'failed-request-123');
    }
}
