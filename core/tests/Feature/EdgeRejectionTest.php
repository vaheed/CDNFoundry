<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgePool;
use App\Support\EdgeCertificateAuthority;
use App\Support\EdgeRejectionReasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeRejectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shared_reason_list_is_the_one_the_agent_sends(): void
    {
        // tests/contract/test_edge_rejection_reasons.py proves the agent embeds
        // this exact file; here the control plane pins what it expects to receive.
        $this->assertSame([
            'incompatible_artifact', 'signature_or_checksum_invalid', 'candidate_validation_failed',
            'invalid_artifact_schema', 'artifact_revision_replay', 'artifact_unavailable',
        ], EdgeRejectionReasons::all());
    }

    public function test_every_known_and_unknown_reason_is_stored_and_never_refused(): void
    {
        [$edge, $headers, $artifacts] = $this->edgeWithArtifacts(2);
        foreach (array_merge(EdgeRejectionReasons::all(), ['a_reason_from_a_newer_agent']) as $index => $reason) {
            $this->withHeaders($headers)->postJson('/edge/v1/config/rejected', ['sequence' => $artifacts[$index % 2]->sequence, 'reason' => $reason, 'details' => 'detail'])
                ->assertOk()->assertJsonPath('data.accepted', true);
        }

        $stored = $edge->refresh()->capacity['last_rejection'];
        $this->assertSame('a_reason_from_a_newer_agent', $stored['reason']);
        $this->assertFalse($stored['known_reason']);
        $this->assertSame($artifacts[0]->domain_id, $stored['domain_id'] ?? $artifacts[0]->domain_id);
    }

    public function test_a_rejection_fails_only_that_domain_and_a_later_applied_artifact_clears_it(): void
    {
        [$edge, $headers, $artifacts] = $this->edgeWithArtifacts(2);

        $this->withHeaders($headers)->postJson('/edge/v1/config/rejected', ['sequence' => $artifacts[0]->sequence, 'reason' => 'candidate_validation_failed'])->assertOk();
        $this->withHeaders($headers)->postJson('/edge/v1/config/applied', ['sequence' => $artifacts[1]->sequence])->assertOk();

        $this->assertSame('failed', DomainEdgePlacement::query()->where('domain_id', $artifacts[0]->domain_id)->value('state'));
        $this->assertSame('deploying', DomainEdgePlacement::query()->where('domain_id', $artifacts[1]->domain_id)->value('state'));
        $this->assertArrayHasKey('last_rejection', $edge->refresh()->capacity, 'a later artifact for a different domain does not resolve it');

        $newer = EdgeArtifact::query()->create([
            'edge_id' => $edge->id, 'domain_id' => $artifacts[0]->domain_id, 'revision' => 9, 'sequence' => $artifacts[1]->sequence + 1,
            'kind' => 'domain', 'checksum' => hash('sha256', 'newer'), 'signature' => 'aa', 'payload' => [],
        ]);
        $this->withHeaders($headers)->postJson('/edge/v1/config/applied', ['sequence' => $newer->sequence])->assertOk();

        $this->assertArrayNotHasKey('last_rejection', $edge->refresh()->capacity);
    }

    /** @return array{0: Edge, 1: array<string,string>, 2: list<EdgeArtifact>} */
    private function edgeWithArtifacts(int $count): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $edge = Edge::query()->create(['name' => 'rejecting', 'country_code' => 'DE', 'continent_code' => 'EU', 'management_ipv4' => '203.0.113.99', 'registered_at' => now(), 'enabled' => true]);
        $csr = openssl_csr_new(['commonName' => $edge->id], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrPem);
        $signed = EdgeCertificateAuthority::sign($csrPem, $edge->id);
        $edge->update([
            'identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'],
            'identity_certificate_expires_at' => $signed['expires_at'],
        ]);
        $headers = ['X-Edge-Proxy-Secret' => config('edge.proxy_secret'), 'X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $signed['serial'], 'X-Edge-Certificate-Pem' => rawurlencode($signed['certificate'])];
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $artifacts = [];
        for ($index = 1; $index <= $count; $index++) {
            $domain = Domain::query()->create(['name' => "rejected-{$index}.example", 'display_name' => 'R', 'revision' => 2]);
            DomainEdgePlacement::query()->create(['domain_id' => $domain->id, 'target_pool_id' => $pool->id, 'desired_revision' => 2, 'state' => 'deploying']);
            DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'desired_revision' => 2, 'state' => 'deploying']);
            $artifacts[] = EdgeArtifact::query()->create([
                'edge_id' => $edge->id, 'domain_id' => $domain->id, 'revision' => 2, 'sequence' => $index, 'kind' => 'domain',
                'checksum' => hash('sha256', "artifact-{$index}"), 'signature' => 'aa', 'payload' => [],
            ]);
        }

        return [$edge, $headers, $artifacts];
    }
}
