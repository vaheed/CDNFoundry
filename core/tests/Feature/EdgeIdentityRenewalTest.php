<?php

namespace Tests\Feature;

use App\Models\Edge;
use App\Support\EdgeCertificateAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeIdentityRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_renewed_identity_is_accepted_beside_the_old_one_until_it_is_proven(): void
    {
        [$edge, $old] = $this->enrolledEdge();
        [$csr] = $this->csr($edge->id);

        $renewed = $this->withHeaders($old)->postJson('/edge/v1/identity/renew', ['certificate_request' => $csr])->assertCreated();
        $new = $this->headersFor($renewed->json('data.identity_certificate'), $renewed->json('data.identity_certificate_serial'));
        $this->assertNotSame($old['X-Edge-Certificate-Serial'], $new['X-Edge-Certificate-Serial']);

        // Both fingerprints work until the new one has authenticated once.
        $this->withHeaders($old)->getJson('/edge/v1/tasks')->assertOk();
        $this->assertNotNull($edge->refresh()->pending_identity_certificate);
        $this->withHeaders($new)->getJson('/edge/v1/tasks')->assertOk();

        $edge->refresh();
        $this->assertNull($edge->pending_identity_certificate);
        $this->assertSame($renewed->json('data.identity_certificate_serial'), $edge->identity_certificate_serial);
        $this->withHeaders($old)->getJson('/edge/v1/tasks')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'edge.identity_renewed']);
    }

    public function test_repeating_the_same_request_returns_the_same_pending_certificate(): void
    {
        [$edge, $old] = $this->enrolledEdge();
        [$csr] = $this->csr($edge->id);

        $first = $this->withHeaders($old)->postJson('/edge/v1/identity/renew', ['certificate_request' => $csr])->assertCreated();
        $second = $this->withHeaders($old)->postJson('/edge/v1/identity/renew', ['certificate_request' => $csr])->assertCreated();

        $this->assertSame($first->json('data.identity_certificate_serial'), $second->json('data.identity_certificate_serial'));
    }

    public function test_a_request_for_another_edge_name_or_without_identity_is_refused(): void
    {
        [$edge, $old] = $this->enrolledEdge();
        [$other] = $this->csr('00000000-0000-4000-8000-000000000000');

        $this->withHeaders($old)->postJson('/edge/v1/identity/renew', ['certificate_request' => $other])->assertUnprocessable();
        $this->flushHeaders()->postJson('/edge/v1/identity/renew', ['certificate_request' => $other])->assertUnauthorized();
        $this->assertNull($edge->refresh()->pending_identity_certificate);
    }

    public function test_the_heartbeat_reports_the_identity_expiry_the_agent_is_using(): void
    {
        [$edge, $headers] = $this->enrolledEdge();
        $edge->cells()->create(['slot' => 1, 'name' => 'cell-01', 'status' => 'ready']);
        $expires = now()->addDays(40)->timestamp;

        $this->withHeaders($headers)->postJson('/edge/v1/heartbeat', [
            'agent_version' => '1.2.1', 'listener_ready' => true, 'active_sequence' => 0, 'identity_expires_at' => $expires,
            'cells' => [['name' => 'cell-01', 'status' => 'ready', 'capacity' => ['active_connections' => 0]]],
        ])->assertOk();

        $this->assertSame($expires, $edge->refresh()->reported_identity_expires_at->timestamp);
    }

    public function test_the_validity_period_is_configurable(): void
    {
        config(['edge.identity_validity_days' => 3]);
        [$edge] = $this->enrolledEdge();

        $this->assertLessThanOrEqual(3 * 86400 + 5, $edge->identity_certificate_expires_at->timestamp - now()->timestamp);
    }

    /** @return array{0: Edge, 1: array<string,string>} */
    private function enrolledEdge(): array
    {
        $edge = Edge::query()->create(['name' => 'renewing', 'country_code' => 'DE', 'continent_code' => 'EU', 'management_ipv4' => '203.0.113.77', 'enabled' => true, 'registered_at' => now()]);
        [$csr] = $this->csr($edge->id);
        $signed = EdgeCertificateAuthority::sign($csr, $edge->id);
        $edge->update(['identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at']]);

        return [$edge->refresh(), $this->headersFor($signed['certificate'], $signed['serial'])];
    }

    /** @return array{0: string} */
    private function csr(string $commonName): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_csr_export(openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']), $pem);

        return [$pem];
    }

    private function headersFor(string $certificate, string $serial): array
    {
        return ['X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $serial, 'X-Edge-Certificate-Pem' => rawurlencode($certificate)];
    }
}
