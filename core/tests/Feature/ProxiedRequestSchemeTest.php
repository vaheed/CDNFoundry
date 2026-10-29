<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxiedRequestSchemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagination_links_keep_the_public_https_scheme_behind_the_tls_proxy(): void
    {
        $admin = User::factory()->admin()->create();

        // Caddy terminates TLS and forwards the public scheme and host.
        $response = $this->actingAs($admin)
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'control.example.test',
                'X-Forwarded-Port' => '443',
            ])
            ->getJson('/api/admin/dns/clusters')
            ->assertOk();

        $this->assertStringStartsWith('https://control.example.test/', $response->json('meta.path'));
    }
}
