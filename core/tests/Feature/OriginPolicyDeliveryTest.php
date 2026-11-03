<?php

namespace Tests\Feature;

use App\Models\Edge;
use App\Models\EdgePool;
use App\Support\ArtifactSigner;
use App\Support\EdgeCertificateAuthority;
use App\Support\OriginPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OriginPolicyDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_policy_is_signed_and_scoped_and_an_unchanged_checksum_returns_nothing_more(): void
    {
        $headers = $this->enrolledHeaders();
        $response = $this->withHeaders($headers)->getJson('/edge/v1/origin-policy')->assertOk();
        $public = hex2bin(ArtifactSigner::publicKey());
        $encoded = base64_decode($response->json('encoded_payload'), true);

        $this->assertSame(hash('sha256', $encoded), $response->json('checksum'));
        $this->assertSame(ArtifactSigner::encode(OriginPolicy::compile()), $encoded);
        $this->assertTrue(sodium_crypto_sign_verify_detached(hex2bin($response->json('signature')), $response->json('checksum'), $public));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            hex2bin($response->json('delivery_signature')),
            "origin-policy|{$response->json('checksum')}|0|{$response->json('expires_at')}",
            $public,
        ));

        $this->withHeaders($headers)->getJson('/edge/v1/origin-policy?checksum='.$response->json('checksum'))
            ->assertOk()->assertExactJson(['unchanged' => true, 'checksum' => $response->json('checksum')]);
    }

    public function test_a_pool_address_change_changes_the_policy_checksum(): void
    {
        $headers = $this->enrolledHeaders();
        $before = $this->withHeaders($headers)->getJson('/edge/v1/origin-policy')->json('checksum');
        EdgePool::query()->where('kind', 'shared')->update(['anycast_ipv4' => '198.51.100.9']);

        $after = $this->withHeaders($headers)->getJson('/edge/v1/origin-policy?checksum='.$before)->assertOk();

        $this->assertNotSame($before, $after->json('checksum'));
        $this->assertContains('198.51.100.9', json_decode(base64_decode($after->json('encoded_payload')), true)['blocked_addresses']);
    }

    public function test_the_policy_requires_an_enrolled_edge_identity(): void
    {
        $this->getJson('/edge/v1/origin-policy')->assertUnauthorized();
    }

    private function enrolledHeaders(): array
    {
        $edge = Edge::query()->create(['name' => 'policy-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => true, 'registered_at' => now()]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_csr_export(openssl_csr_new(['commonName' => $edge->id], $key, ['digest_alg' => 'sha256']), $csr);
        $signed = EdgeCertificateAuthority::sign($csr, $edge->id);
        $edge->update(['identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at']]);

        return ['X-Edge-Proxy-Secret' => config('edge.proxy_secret'), 'X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $signed['serial'], 'X-Edge-Certificate-Pem' => rawurlencode($signed['certificate'])];
    }
}
