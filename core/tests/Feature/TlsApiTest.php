<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Jobs\EnsureManagedCertificates;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificateChain;
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

    public function test_custom_upload_accepts_a_valid_private_ca_chain(): void
    {
        [$user, $domain] = $this->proxiedDomain();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", CertificateChain::make('valid'))
            ->assertAccepted()->assertJsonPath('data.certificate.kind', 'custom');
    }

    public function test_custom_upload_normalizes_the_public_chain_without_storing_extra_private_key_material(): void
    {
        [$user, $domain] = $this->proxiedDomain();
        $bundle = CertificateChain::make('valid');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", [
            ...$bundle, 'chain' => "Exported certificate bundle\n".$bundle['chain'].$bundle['private_key'],
        ])->assertAccepted();

        $stored = DB::table('tls_certificates')->where('domain_id', $domain->id)->value('chain_pem');
        $this->assertFalse(str_contains($stored, 'PRIVATE KEY'), 'The public chain must not contain private-key PEM.');
        $this->assertSame($bundle['chain'], $stored);
    }

    #[DataProvider('invalidChainConstraints')]
    public function test_custom_upload_rejects_invalid_chain_constraints_without_replacing_active_state(string $constraint): void
    {
        [$user, $domain] = $this->proxiedDomain();
        $domain->update(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()]);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", CertificateChain::make('valid'))->assertAccepted();
        $domain->refresh();
        $revision = $domain->revision;
        $certificateId = $domain->active_tls_certificate_id;
        $artifactCount = EdgeRevision::query()->where('domain_id', $domain->id)->count();

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", CertificateChain::make($constraint))
            ->assertUnprocessable()->assertJsonValidationErrors('chain');

        $this->assertSame($revision, $domain->refresh()->revision);
        $this->assertSame($certificateId, $domain->active_tls_certificate_id);
        $this->assertSame('active', TlsCertificate::query()->findOrFail($certificateId)->status);
        $this->assertSame(1, TlsCertificate::query()->where('domain_id', $domain->id)->count());
        $this->assertSame($artifactCount, EdgeRevision::query()->where('domain_id', $domain->id)->count());
    }

    #[DataProvider('uploadEntrypoints')]
    public function test_repeat_upload_replaces_a_legacy_unnormalized_chain(bool $panel): void
    {
        [$user, $domain] = $this->proxiedDomain();
        $domain->update(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()]);
        $bundle = CertificateChain::make('valid');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", $bundle)->assertAccepted();
        $domain->refresh();
        $id = $domain->active_tls_certificate_id;
        $revision = $domain->revision;
        DB::table('tls_certificates')->where('id', $id)->update(['chain_pem' => $bundle['chain'].$bundle['private_key']]);

        if ($panel) {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            Livewire::test(ViewDomain::class, ['record' => $domain->id])
                ->callAction('uploadCertificate', data: $bundle)->assertHasNoActionErrors();
        } else {
            $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", $bundle)->assertAccepted();
        }

        $stored = DB::table('tls_certificates')->where('id', $id)->value('chain_pem');
        $this->assertFalse(str_contains($stored, 'PRIVATE KEY'), 'Repeat upload must replace legacy public-chain material.');
        $this->assertSame($bundle['chain'], $stored);
        $this->assertSame($id, $domain->refresh()->active_tls_certificate_id);
        $this->assertSame($revision + 1, $domain->revision);
        $this->assertSame(1, $domain->tlsCertificates()->count());
        $snapshot = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail()->snapshot;
        $this->assertSame($bundle['chain'], $snapshot['tls']['certificate']['chain_pem']);
    }

    public static function uploadEntrypoints(): array
    {
        return ['api' => [false], 'panel' => [true]];
    }

    public function test_mounted_panel_upload_rechecks_revoked_domain_access(): void
    {
        [$user, $domain] = $this->proxiedDomain();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($user);
        $component = Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->mountAction('uploadCertificate')
            ->set('mountedActions.0.data', CertificateChain::make('valid'));
        $domain->users()->detach($user);

        $component->callMountedAction()->assertForbidden();

        $this->assertSame(1, $domain->refresh()->revision);
        $this->assertNull($domain->active_tls_certificate_id);
        $this->assertDatabaseCount('tls_certificates', 0);
        $this->assertDatabaseCount('operations', 0);
    }

    #[DataProvider('uploadEntrypoints')]
    public function test_upload_does_not_commit_after_the_validated_domain_revision_changes(bool $panel): void
    {
        [$user, $domain] = $this->proxiedDomain();
        $domain->update(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()]);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/upload", CertificateChain::make('valid'))->assertAccepted();
        $domain->refresh();
        $id = $domain->active_tls_certificate_id;
        $revision = $domain->revision;
        $artifacts = EdgeRevision::query()->where('domain_id', $domain->id)->count();
        $bundle = CertificateChain::make('valid');
        // Deterministic entry-point regression; postgres_domain_claims.py
        // separately interleaves real processes and the real OpenSSL verifier.
        Process::fake(function () use ($domain) {
            Domain::query()->whereKey($domain->id)->increment('revision');

            return Process::result();
        });

        if ($panel) {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            Livewire::test(ViewDomain::class, ['record' => $domain->id])
                ->callAction('uploadCertificate', data: $bundle)->assertHasActionErrors(['certificate']);
        } else {
            $this->postJson("/api/domains/{$domain->id}/tls/upload", $bundle)
                ->assertConflict()->assertJsonPath('code', 'conflict');
        }

        $this->assertSame($revision + 1, $domain->refresh()->revision);
        $this->assertSame($id, $domain->active_tls_certificate_id);
        $this->assertSame(1, $domain->tlsCertificates()->count());
        $this->assertSame($artifacts, EdgeRevision::query()->where('domain_id', $domain->id)->count());
    }

    public static function invalidChainConstraints(): array
    {
        $names = ['issuer_not_ca', 'issuer_key_usage', 'expired_issuer', 'expired_root', 'path_length', 'server_purpose', 'name_constraint', 'critical_extension',
            'weak_issuer_key', 'weak_root_key', 'weak_leaf_signature', 'weak_issuer_signature'];

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
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
