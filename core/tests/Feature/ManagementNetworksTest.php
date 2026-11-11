<?php

namespace Tests\Feature;

use Tests\TestCase;

class ManagementNetworksTest extends TestCase
{
    private function viaCaddy(string $client): array
    {
        return ['REMOTE_ADDR' => '172.20.0.5', 'HTTP_X_FORWARDED_FOR' => $client, 'HTTP_X_FORWARDED_PROTO' => 'https'];
    }

    public function test_outside_clients_get_a_bare_403_without_cookie_or_redirect(): void
    {
        config(['security.management_networks' => '198.51.100.0/24 2001:db8::/32']);

        foreach (['/', '/admin', '/admin/login', '/app', '/horizon', '/health', '/metrics', '/control-state'] as $path) {
            $response = $this->call('GET', $path, [], [], [], $this->viaCaddy('203.0.113.9'));
            $response->assertForbidden();
            $this->assertSame('Forbidden', $response->getContent(), $path);
            $this->assertNull($response->headers->get('Location'), $path);
            $this->assertSame([], $response->headers->getCookies(), $path);
        }
        $this->call('GET', '/api/domains', [], [], [], $this->viaCaddy('203.0.113.9'))
            ->assertForbidden()->assertJsonPath('code', 'network_not_allowed');
    }

    public function test_health_probes_and_the_edge_listener_stay_reachable(): void
    {
        config(['security.management_networks' => '198.51.100.0/24']);

        foreach (['/up', '/api/health', '/api/ready'] as $path) {
            $this->assertNotSame(403, $this->call('GET', $path, [], [], [], $this->viaCaddy('203.0.113.9'))->getStatusCode(), $path);
        }
        $this->assertNotSame(403, $this->call('GET', '/edge/v1/config/manifest', [], [], [], $this->viaCaddy('203.0.113.9'))->getStatusCode());
    }

    public function test_inside_clients_and_direct_internal_callers_pass(): void
    {
        config(['security.management_networks' => '198.51.100.0/24 2001:db8::/32']);

        $this->assertNotSame(403, $this->call('GET', '/admin/login', [], [], [], $this->viaCaddy('198.51.100.77'))->getStatusCode());
        $this->assertNotSame(403, $this->call('GET', '/admin/login', [], [], [], $this->viaCaddy('2001:db8::7'))->getStatusCode());
        // Prometheus and other containers reach the application directly, with no forwarded address.
        $this->assertNotSame(403, $this->call('GET', '/api/domains', [], [], [], ['REMOTE_ADDR' => '172.20.0.9'])->getStatusCode());
    }

    public function test_an_empty_list_restricts_nothing(): void
    {
        config(['security.management_networks' => '']);

        $this->assertNotSame(403, $this->call('GET', '/admin/login', [], [], [], $this->viaCaddy('203.0.113.9'))->getStatusCode());
    }

    public function test_a_spoofed_forwarded_header_from_an_untrusted_peer_does_not_grant_access(): void
    {
        config(['security.management_networks' => '198.51.100.0/24']);

        // The peer is a public address, so its forwarded header is ignored and its own address is judged.
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        $this->call('GET', '/admin/login', [], [], [], $server)->assertForbidden();
    }
}
