<?php

namespace Tests\Feature;

use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecretExposureSweepTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETS = ['SWEEPKEYMATERIAL', 'SWEEPAPIKEY', 'BEGIN PRIVATE KEY', 'SWEEPPOWERDNSERROR'];

    public function test_no_domain_user_endpoint_returns_key_material_or_secrets(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'sweep.example.test', 'display_name' => 'Sweep', 'lifecycle_state' => 'active', 'revision' => 1]);
        $domain->users()->attach($user);
        $certificate = $domain->tlsCertificates()->create([
            'kind' => 'custom', 'status' => 'active', 'certificate_pem' => 'cert', 'chain_pem' => '',
            'private_key_ciphertext' => "-----BEGIN PRIVATE KEY-----\nSWEEPKEYMATERIAL\n-----END PRIVATE KEY-----",
            'names' => ['sweep.example.test'], 'fingerprint_sha256' => str_repeat('b', 64), 'not_before' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);
        $domain->update(['tls_mode' => 'custom', 'active_tls_certificate_id' => $certificate->id]);
        EdgeRevision::query()->create(['domain_id' => $domain->id, 'revision' => 1, 'checksum' => str_repeat('c', 64), 'status' => 'validated', 'snapshot' => ['tls' => ['certificate' => ['private_key_pem' => 'SWEEPKEYMATERIAL']]]]);
        $cluster = DnsCluster::query()->create(['name' => 'sweep', 'location' => 'x', 'enabled' => true, 'api_url' => 'https://dns.example.test', 'api_key' => 'SWEEPAPIKEY', 'server_id' => 'localhost', 'nameservers' => [], 'capacity_zones' => 10]);
        $domain->dnsDeployments()->create(['dns_cluster_id' => $cluster->id, 'desired_revision' => 1, 'status' => 'failed', 'last_error' => 'SWEEPPOWERDNSERROR']);
        $operation = Operation::query()->create(['actor_id' => $user->id, 'type' => 'dns.zone_reconcile', 'status' => 'failed', 'input' => ['domain_id' => $domain->id], 'error' => 'denied']);

        $checked = 0;
        $uris = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/') || str_starts_with($uri, 'api/admin')) {
                continue;
            }
            $parameters = $route->parameterNames();
            if ($parameters === ['domain']) {
                $uris[] = '/'.str_replace('{domain}', (string) $domain->id, $uri);
            }
        }
        $uris[] = "/api/operations/{$operation->id}";
        $this->assertGreaterThan(10, count($uris));
        foreach ($uris as $uri) {
            $body = $this->actingAs($user)->getJson($uri)->getContent();
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, (string) $body, "{$uri} exposed {$secret}");
            }
            $checked++;
        }
        $this->assertGreaterThan(10, $checked);
    }
}
