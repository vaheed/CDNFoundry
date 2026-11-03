<?php

namespace Tests\Feature;

use App\Models\Edge;
use App\Support\EdgeCertificateAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeProxySecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_genuine_edge_identity_is_refused_without_the_proxy_secret(): void
    {
        $edge = Edge::query()->create(['name' => 'secret-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => true, 'registered_at' => now()]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $edge->id], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrPem);
        $signed = EdgeCertificateAuthority::sign($csrPem, $edge->id);
        $edge->update(['identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at']]);
        $identity = ['X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $signed['serial'], 'X-Edge-Certificate-Pem' => rawurlencode($signed['certificate'])];

        $this->withHeaders($identity)->getJson('/edge/v1/tasks')->assertUnauthorized();
        $this->withHeaders($identity + ['X-Edge-Proxy-Secret' => 'a-guessed-secret-0123456789abcdef0123'])->getJson('/edge/v1/tasks')->assertUnauthorized();
        $this->withHeaders($identity + ['X-Edge-Proxy-Secret' => config('edge.proxy_secret')])->getJson('/edge/v1/tasks')->assertOk();
    }

    public function test_an_unconfigured_secret_fails_closed(): void
    {
        config(['edge.proxy_secret' => '']);

        $this->withHeaders(['X-Edge-Proxy-Secret' => ''])->getJson('/edge/v1/tasks')->assertUnauthorized();
    }
}
