<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrowserSecurityHeadersTest extends TestCase
{
    public function test_panels_send_an_enforced_policy_without_wildcards(): void
    {
        $response = $this->get('/admin/login');
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{24}'/", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertDoesNotMatchRegularExpression('/(^|[ ;])(\*|https?:\*?)([ ;]|$)/', $policy);
        $this->assertStringNotContainsString("'unsafe-inline'", explode('style-src', $policy)[0], 'scripts never run inline without the nonce');
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_nonce_changes_on_every_request_and_matches_inline_scripts(): void
    {
        $first = $this->get('/admin/login');
        $second = $this->get('/admin/login');
        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $first->headers->get('Content-Security-Policy'), $one);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $second->headers->get('Content-Security-Policy'), $two);

        $this->assertNotSame($one[1], $two[1]);
        preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/', $first->getContent(), $inline);
        foreach ($inline[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$one[1].'"', $tag, "inline script without the request nonce: {$tag}");
        }
    }

    public function test_report_only_and_off_modes(): void
    {
        config(['security.content_security_policy' => 'report-only']);
        $reported = $this->get('/admin/login');
        $this->assertNull($reported->headers->get('Content-Security-Policy'));
        $this->assertNotNull($reported->headers->get('Content-Security-Policy-Report-Only'));

        config(['security.content_security_policy' => 'off']);
        $off = $this->get('/admin/login');
        $this->assertNull($off->headers->get('Content-Security-Policy'));
        $this->assertNull($off->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_cross_origin_requests_are_refused_unless_the_origin_is_configured(): void
    {
        $preflight = ['HTTP_ORIGIN' => 'https://attacker.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization'];
        $this->call('OPTIONS', '/api/domains', [], [], [], $preflight)
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->call('GET', '/api/health', [], [], [], ['HTTP_ORIGIN' => 'https://attacker.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        config(['cors.allowed_origins' => ['https://console.example.test']]);
        $allowed = ['HTTP_ORIGIN' => 'https://console.example.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization'];
        $this->call('OPTIONS', '/api/domains', [], [], [], $allowed)
            ->assertHeader('Access-Control-Allow-Origin', 'https://console.example.test');
    }
}
