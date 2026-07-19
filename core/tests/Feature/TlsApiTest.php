<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Jobs\EnsureManagedCertificates;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TlsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_upload_validates_coverage_encrypts_key_and_queues_revision(): void
    {
        [$user, $domain] = $this->proxiedDomain();
        $bundle = $this->certificate(['example.test', '*.example.test']);

        $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", $bundle)
            ->assertAccepted()->assertJsonPath('data.certificate.kind', 'custom')->assertJsonPath('data.certificate.status', 'active');
        $certificate = TlsCertificate::query()->findOrFail($response->json('data.certificate.id'));
        $this->assertSame('custom', $domain->refresh()->tls_mode);
        $this->assertSame($certificate->id, $domain->active_tls_certificate_id);
        $this->assertSame(trim($bundle['private_key']), trim($certificate->private_key_ciphertext));
        $this->assertNotSame($bundle['private_key'], DB::table('tls_certificates')->where('id', $certificate->id)->value('private_key_ciphertext'));
        $this->assertArrayNotHasKey('private_key', $response->json('data.certificate'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'tls.custom_uploaded', 'subject_id' => $certificate->id]);
        $snapshot = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail()->snapshot;
        $this->assertSame($certificate->id, $snapshot['tls']['certificate']['id']);
        $this->assertSame(trim($bundle['private_key']), trim($snapshot['tls']['certificate']['private_key_pem']));
    }

    public function test_custom_upload_rejects_wrong_key_missing_name_and_invalid_chain(): void
    {
        [$user, $domain] = $this->proxiedDomain();
        $bundle = $this->certificate(['example.test', '*.example.test']);
        $other = $this->certificate(['example.test', '*.example.test']);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", [...$bundle, 'private_key' => $other['private_key']])
            ->assertUnprocessable()->assertJsonValidationErrors('private_key');

        $missing = $this->certificate(['unrelated.example']);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", $missing)
            ->assertUnprocessable()->assertJsonValidationErrors('certificate');

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", [...$bundle, 'chain' => $other['chain']])
            ->assertUnprocessable()->assertJsonValidationErrors('chain');
        $this->assertSame(0, TlsCertificate::query()->count());
    }

    public function test_tls_mode_requires_custom_certificate_and_deletion_returns_to_managed(): void
    {
        [$user, $domain] = $this->proxiedDomain();
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/tls", ['mode' => 'custom'])->assertConflict();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", $this->certificate(['example.test', '*.example.test']))->assertAccepted();
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/tls")->assertOk()->assertJsonPath('data.mode', 'custom');
        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}/tls/custom-certificate")->assertAccepted();
        $this->assertSame('managed', $domain->refresh()->tls_mode);
        $this->assertNull($domain->active_tls_certificate_id);
        $this->assertSame('revoked', TlsCertificate::query()->firstOrFail()->status);
    }

    public function test_managed_reissue_is_authorized_asynchronous_and_status_never_exposes_key_material(): void
    {
        Queue::fake();
        [$user, $domain] = $this->proxiedDomain();
        $domain->update(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()]);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/reissue")
            ->assertAccepted()->assertJsonPath('data.status', 'pending');
        Queue::assertPushed(EnsureManagedCertificates::class, fn ($job): bool => $job->domainId === $domain->id && $job->force);

        TlsOrder::query()->create([
            'domain_id' => $domain->id, 'status' => 'finalizing', 'names' => ['example.test'],
            'names_hash' => hash('sha256', 'example.test'), 'private_key_ciphertext' => 'very-secret-private-key',
            'csr_der' => 'secret-csr',
        ]);
        $payload = $this->actingAs($user)->getJson("/api/domains/{$domain->id}/tls/status")->assertOk()->json();
        $this->assertStringNotContainsString('very-secret-private-key', json_encode($payload));
        $this->assertArrayNotHasKey('private_key_ciphertext', $payload['data']['latest_order']);
        $this->assertArrayNotHasKey('csr_der', $payload['data']['latest_order']);
    }

    private function proxiedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.test', 'display_name' => 'Example', 'revision' => 1]);
        $domain->users()->attach($user);
        $domain->dnsRecords()->create([
            'type' => 'A', 'name' => 'www.example.test', 'content' => '8.8.8.8', 'ttl' => 300, 'mode' => 'proxied',
            'origin' => ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'www.example.test', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => null],
            'content_hash' => hash('sha256', 'origin'),
        ]);

        return [$user, $domain];
    }

    private function certificate(array $names): array
    {
        $caKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $caRequest = openssl_csr_new(['commonName' => 'CDNFoundry Test Root'], $caKey, ['digest_alg' => 'sha256']);
        $ca = openssl_csr_sign($caRequest, null, $caKey, 30, ['digest_alg' => 'sha256'], random_int(1, 1000000));
        openssl_x509_export($ca, $caPem);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $config = tempnam(sys_get_temp_dir(), 'cdnf-openssl-');
        file_put_contents($config, "[ req ]\ndistinguished_name = dn\nreq_extensions = san\nprompt = no\n[ dn ]\nCN = {$names[0]}\n[ san ]\nsubjectAltName = ".implode(',', array_map(fn (string $name): string => 'DNS:'.$name, $names))."\n");
        $request = openssl_csr_new(['commonName' => $names[0]], $key, ['digest_alg' => 'sha256', 'config' => $config, 'req_extensions' => 'san']);
        $leaf = openssl_csr_sign($request, $ca, $caKey, 10, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'san'], random_int(1, 1000000));
        unlink($config);
        openssl_x509_export($leaf, $leafPem);
        openssl_pkey_export($key, $keyPem);

        return ['certificate' => $leafPem, 'chain' => $caPem, 'private_key' => $keyPem];
    }
}
