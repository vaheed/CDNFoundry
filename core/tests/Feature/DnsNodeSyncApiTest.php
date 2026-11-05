<?php

namespace Tests\Feature;

use App\Models\DnsCluster;
use App\Models\DnsZoneChange;
use App\Models\Edge;
use App\Support\EdgeCertificateAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnsNodeSyncApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_are_returned_oldest_first_after_the_cursor_and_in_bounded_pages(): void
    {
        $headers = $this->enrolledHeaders();
        foreach (range(1, 205) as $number) {
            DnsZoneChange::record("zone{$number}.test", 2026110100 + $number);
        }
        DnsZoneChange::record('zone3.test', 2026110999, deleted: true);

        $first = $this->withHeaders($headers)->getJson('/edge/v1/dns/changes')->assertOk();
        $this->assertCount(200, $first->json('data'));
        $this->assertTrue($first->json('has_more'));
        $this->assertSame(array_column($first->json('data'), 'sequence'), collect($first->json('data'))->pluck('sequence')->sort()->values()->all());
        $this->assertNotContains('zone3.test', array_column(array_slice($first->json('data'), 0, 3), 'zone'), 'a changed zone moves to the end');

        $second = $this->withHeaders($headers)->getJson('/edge/v1/dns/changes?cursor='.$first->json('cursor'))->assertOk();
        $this->assertSame(['zone3.test'], array_values(array_intersect(array_column($second->json('data'), 'zone'), ['zone3.test'])));
        $this->assertFalse($second->json('has_more'));
        $this->assertSame(DnsZoneChange::query()->max('id'), $second->json('latest'));
        $this->assertTrue(collect($second->json('data'))->firstWhere('zone', 'zone3.test')['deleted']);

        $this->withHeaders($headers)->getJson('/edge/v1/dns/changes?cursor='.$second->json('cursor'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_transfer_key_is_sealed_to_the_edge_and_never_sent_in_the_clear(): void
    {
        $headers = $this->enrolledHeaders();
        $this->withHeaders($headers)->getJson('/edge/v1/dns/transfer')->assertNotFound();

        $cluster = DnsCluster::query()->create([
            'name' => 'primary', 'location' => 'control', 'enabled' => true, 'api_url' => 'http://pdns.test', 'api_key' => 'private-test-key',
            'transfer_endpoint' => '192.0.2.10:5300', 'nameservers' => [['hostname' => 'a.test'], ['hostname' => 'b.test']],
        ]);
        $response = $this->withHeaders($headers)->getJson('/edge/v1/dns/transfer')->assertOk()
            ->assertJsonPath('data.primary', '192.0.2.10:5300')->assertJsonPath('data.key_name', 'cdnf-nodes')->assertJsonPath('data.algorithm', 'hmac-sha256');

        $this->assertStringNotContainsString($cluster->transfer_tsig_secret, $response->getContent());
        $this->assertNotEmpty($response->json('data.secret'));
    }

    public function test_the_feed_and_the_transfer_key_require_an_enrolled_edge(): void
    {
        $this->getJson('/edge/v1/dns/changes')->assertUnauthorized();
        $this->getJson('/edge/v1/dns/transfer')->assertUnauthorized();
    }

    private function enrolledHeaders(): array
    {
        $edge = Edge::query()->create(['name' => 'dns-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => true, 'registered_at' => now()]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_csr_export(openssl_csr_new(['commonName' => $edge->id], $key, ['digest_alg' => 'sha256']), $csr);
        $signed = EdgeCertificateAuthority::sign($csr, $edge->id);
        $edge->update(['identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at']]);

        return ['X-Edge-Proxy-Secret' => config('edge.proxy_secret'), 'X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $signed['serial'], 'X-Edge-Certificate-Pem' => rawurlencode($signed['certificate'])];
    }
}
