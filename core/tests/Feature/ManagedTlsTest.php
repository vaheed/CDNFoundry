<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Enums\UserType;
use App\Jobs\EnsureManagedCertificates;
use App\Jobs\IssueManagedCertificate;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Models\User;
use App\Support\AcmeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ManagedTlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dns_only_and_unverified_domains_do_not_create_orders(): void
    {
        Queue::fake([EnsureManagedCertificates::class]);
        $domain = Domain::query()->create(['name' => 'example.test', 'display_name' => 'Example', 'revision' => 1]);
        (new EnsureManagedCertificates($domain->id))->handle();
        $this->assertDatabaseCount('tls_orders', 0);

        $domain->update(['lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()]);
        (new EnsureManagedCertificates($domain->id))->handle();
        $this->assertDatabaseCount('tls_orders', 0);
    }

    public function test_managed_dns01_order_activates_only_after_dns_ack_and_cleans_challenges(): void
    {
        Queue::fake();
        config()->set('services.acme.enabled', true);
        config()->set('services.acme.contact_email', 'admin@example.test');
        config()->set('services.acme.directory_url', 'https://acme.test/directory');
        $domain = $this->proxiedDomain();
        $cluster = DnsCluster::query()->create([
            'name' => 'test', 'location' => 'test', 'enabled' => true, 'last_health_status' => 'healthy',
            'api_url' => 'https://pdns.test', 'api_key' => 'secret', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.example.test'], ['hostname' => 'ns2.example.test']], 'capacity_zones' => 100,
        ]);
        DnsDeployment::query()->create(['domain_id' => $domain->id, 'dns_cluster_id' => $cluster->id, 'active_rrsets' => []]);

        (new EnsureManagedCertificates($domain->id))->handle();
        $order = TlsOrder::query()->firstOrFail();
        $this->assertSame(['example.test', '*.example.test'], $order->names);
        $this->fakeAcme();
        $job = new IssueManagedCertificate($order->id);
        $client = app(AcmeClient::class);

        $order->update(['available_at' => now()->subSecond()]);
        $job->handle($client);
        $order->refresh();
        $this->assertSame('publishing', $order->status);
        $this->assertDatabaseHas('acme_challenges', ['record_name' => '_acme-challenge.example.test', 'status' => 'published']);
        $this->assertNotSame(DB::table('acme_accounts')->first()->private_key_ciphertext, DB::table('acme_accounts')->first()->contact_email);

        $order->update(['next_poll_at' => now()->subSecond()]);
        $job->handle($client);
        $this->assertSame('publishing', $order->refresh()->status, 'CA validation must not start before every DNS deployment acknowledges the challenge revision.');
        DnsDeployment::query()->where('domain_id', $domain->id)->update(['status' => 'succeeded', 'deployed_revision' => $order->dns_revision]);

        foreach (['publishing', 'validating', 'finalizing'] as $expected) {
            $order->update(['next_poll_at' => now()->subSecond()]);
            $job->handle($client);
            $this->assertSame($expected === 'finalizing' ? 'succeeded' : ($expected === 'publishing' ? 'validating' : 'finalizing'), $order->refresh()->status);
        }
        $certificate = TlsCertificate::query()->firstOrFail();
        $this->assertSame($certificate->id, $domain->refresh()->active_tls_certificate_id);
        $this->assertSame('active', $certificate->status);
        $this->assertNotSame($certificate->private_key_ciphertext, DB::table('tls_certificates')->where('id', $certificate->id)->value('private_key_ciphertext'));
        $this->assertDatabaseHas('acme_challenges', ['status' => 'cleaned']);
        $this->assertDatabaseHas('operations', ['type' => 'tls.managed_certificate', 'status' => 'succeeded']);
    }

    public function test_deep_hostname_gets_a_bounded_supplemental_order_and_valid_certificate_is_reused(): void
    {
        Queue::fake();
        $domain = $this->proxiedDomain('a.b.example.test');
        (new EnsureManagedCertificates($domain->id))->handle();
        $this->assertSame([
            ['example.test', '*.example.test'], ['a.b.example.test'],
        ], TlsOrder::query()->orderBy('created_at')->get()->pluck('names')->all());

        $domain->tlsCertificates()->create([
            'kind' => 'managed', 'status' => 'active', 'certificate_pem' => 'pem', 'chain_pem' => '',
            'private_key_ciphertext' => 'key', 'names' => ['example.test', '*.example.test'],
            'fingerprint_sha256' => str_repeat('a', 64), 'not_before' => now()->subDay(),
            'expires_at' => now()->addDays(60), 'activated_at' => now(),
        ]);
        TlsOrder::query()->delete();
        (new EnsureManagedCertificates($domain->id))->handle();
        $this->assertSame([['a.b.example.test']], TlsOrder::query()->get()->pluck('names')->all());
    }

    public function test_expiring_and_failed_certificates_create_deduplicated_administrator_alerts(): void
    {
        Queue::fake([EnsureManagedCertificates::class]);
        $admin = User::factory()->create(['type' => UserType::Admin]);
        $domain = $this->proxiedDomain();
        $certificate = $domain->tlsCertificates()->create([
            'kind' => 'managed', 'status' => 'active', 'certificate_pem' => 'pem', 'chain_pem' => '',
            'private_key_ciphertext' => 'key', 'names' => ['example.test'], 'fingerprint_sha256' => str_repeat('b', 64),
            'not_before' => now()->subDay(), 'expires_at' => now()->addDays(5), 'activated_at' => now(),
        ]);
        $order = TlsOrder::query()->create([
            'domain_id' => $domain->id, 'status' => 'failed', 'names' => ['example.test'],
            'names_hash' => hash('sha256', 'example.test'), 'last_error' => 'CA rate limited this order', 'finished_at' => now(),
        ]);

        $this->artisan('tls:dispatch-maintenance')->assertSuccessful();
        $this->assertCount(2, $admin->notifications()->get());
        $this->assertNotNull($certificate->refresh()->alerted_at);
        $this->assertNotNull($order->refresh()->alerted_at);
        $this->artisan('tls:dispatch-maintenance')->assertSuccessful();
        $this->assertCount(2, $admin->notifications()->get());
    }

    public function test_exhausted_issuance_cleans_dns_state_and_preserves_the_active_certificate(): void
    {
        Queue::fake();
        $domain = $this->proxiedDomain();
        $certificate = $domain->tlsCertificates()->create([
            'kind' => 'managed', 'status' => 'active', 'certificate_pem' => 'last-valid', 'chain_pem' => '',
            'private_key_ciphertext' => 'last-valid-key', 'names' => ['example.test', '*.example.test'],
            'fingerprint_sha256' => str_repeat('c', 64), 'not_before' => now()->subDay(),
            'expires_at' => now()->addDays(30), 'activated_at' => now(),
        ]);
        $domain->update(['active_tls_certificate_id' => $certificate->id]);
        $order = TlsOrder::query()->create([
            'domain_id' => $domain->id, 'status' => 'validating', 'names' => ['example.test', '*.example.test'],
            'names_hash' => hash('sha256', "example.test\0*.example.test"), 'dns_revision' => $domain->revision,
            'last_error' => 'validation remained unavailable',
        ]);
        $order->challenges()->create([
            'hostname' => 'example.test', 'record_name' => '_acme-challenge.example.test', 'record_value' => 'challenge-value',
            'authorization_url' => 'https://acme.test/auth/failed', 'challenge_url' => 'https://acme.test/challenge/failed',
            'status' => 'validating', 'expires_at' => now()->addMinutes(10),
        ]);
        $operation = Operation::query()->create([
            'type' => 'tls.managed_certificate', 'status' => 'running',
            'input' => ['domain_id' => $domain->id, 'order_id' => $order->id],
        ]);
        $revision = $domain->revision;

        (new IssueManagedCertificate($order->id))->failed(new RuntimeException('CA validation exhausted its retry budget.'));

        $this->assertSame('failed', $order->refresh()->status);
        $this->assertSame('cleaned', $order->challenges()->firstOrFail()->status);
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame($certificate->id, $domain->refresh()->active_tls_certificate_id);
        $this->assertSame('active', $certificate->refresh()->status);
        $this->assertSame($revision + 1, $domain->revision);
    }

    private function proxiedDomain(string $hostname = 'www.example.test'): Domain
    {
        $domain = Domain::query()->create([
            'name' => 'example.test', 'display_name' => 'Example', 'revision' => 1,
            'lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now(),
        ]);
        $domain->dnsRecords()->create([
            'type' => 'A', 'name' => $hostname, 'content' => '8.8.8.8', 'ttl' => 300, 'mode' => 'proxied',
            'origin' => ['host' => '8.8.8.8'], 'content_hash' => hash('sha256', $hostname),
        ]);

        return $domain;
    }

    private function fakeAcme(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($url === 'https://acme.test/directory') {
                return Http::response(['newNonce' => 'https://acme.test/nonce', 'newAccount' => 'https://acme.test/account', 'newOrder' => 'https://acme.test/new-order']);
            }
            if ($url === 'https://acme.test/nonce') {
                return Http::response('', 200, ['Replay-Nonce' => 'test-nonce']);
            }
            if ($url === 'https://acme.test/account') {
                return Http::response(['status' => 'valid'], 201, ['Location' => 'https://acme.test/accounts/1']);
            }
            if ($url === 'https://acme.test/new-order') {
                return Http::response(['authorizations' => ['https://acme.test/auth/1'], 'finalize' => 'https://acme.test/finalize/1'], 201, ['Location' => 'https://acme.test/order/1']);
            }
            if ($url === 'https://acme.test/auth/1') {
                $order = TlsOrder::query()->first();
                if ($order?->status === 'validating') {
                    return Http::response(['status' => 'valid']);
                }

                return Http::response(['status' => 'pending', 'identifier' => ['type' => 'dns', 'value' => 'example.test'], 'challenges' => [
                    ['type' => 'dns-01', 'url' => 'https://acme.test/challenge/1', 'token' => 'token'],
                ]]);
            }
            if ($url === 'https://acme.test/challenge/1' || $url === 'https://acme.test/finalize/1') {
                return Http::response(['status' => 'processing']);
            }
            if ($url === 'https://acme.test/order/1') {
                return Http::response(['status' => 'valid', 'certificate' => 'https://acme.test/certificate/1']);
            }
            if ($url === 'https://acme.test/certificate/1') {
                return Http::response($this->issuedCertificate());
            }

            return Http::response(['detail' => 'unexpected '.$url], 500);
        });
    }

    private function issuedCertificate(): string
    {
        $order = TlsOrder::query()->firstOrFail();
        $key = openssl_pkey_get_private($order->private_key_ciphertext);
        $config = tempnam(sys_get_temp_dir(), 'cdnf-managed-test-');
        file_put_contents($config, "[req]\ndistinguished_name=dn\nreq_extensions=san\nprompt=no\n[dn]\nCN=example.test\n[san]\nsubjectAltName=DNS:example.test,DNS:*.example.test\n");
        $csr = openssl_csr_new(['commonName' => 'example.test'], $key, ['config' => $config, 'req_extensions' => 'san']);
        $certificate = openssl_csr_sign($csr, null, $key, 60, ['config' => $config, 'x509_extensions' => 'san'], 1);
        unlink($config);
        openssl_x509_export($certificate, $pem);

        return $pem;
    }
}
