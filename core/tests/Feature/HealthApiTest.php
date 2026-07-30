<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_does_not_require_authentication(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
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
