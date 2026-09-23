<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Enums\UserType;
use App\Jobs\EnsureManagedCertificates;
use App\Jobs\IssueManagedCertificate;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AcmeAccount;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\TlsCertificate;
use App\Models\TlsOrder;
use App\Models\User;
use App\Support\AcmeClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ManagedTlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_acme_jwk_left_pads_short_p256_coordinates(): void
    {
        config()->set('services.acme.enabled', true);
        config()->set('services.acme.contact_email', 'admin@example.test');
        config()->set('services.acme.directory_url', 'https://acme.test/directory');
        AcmeAccount::query()->create([
            'directory_url' => 'https://acme.test/directory',
            'contact_email' => 'admin@example.test',
            'private_key_ciphertext' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQge3srW6PnbyQqZeu9
aD5msjmJVB5iRCGBKjh98v3+8PuhRANCAAR7i/QTNdPNQiqYjcEJYJun41iXKPw7
FokvF9cyhH/WfwCNKy3Sb/n0fUrQdhkX3I9WBgLnS3bd5K+tAzcNDigs
-----END PRIVATE KEY-----
PEM,
        ]);
        $accountRequest = null;
        Http::fake(function (Request $request) use (&$accountRequest) {
            if ($request->url() === 'https://acme.test/directory') {
                return Http::response([
                    'newNonce' => 'https://acme.test/nonce',
                    'newAccount' => 'https://acme.test/account',
                    'newOrder' => 'https://acme.test/new-order',
                ]);
            }
            if ($request->url() === 'https://acme.test/nonce') {
                return Http::response('', 200, ['Replay-Nonce' => 'test-nonce']);
            }
            if ($request->url() === 'https://acme.test/account') {
                $accountRequest = $request;

                return Http::response(['status' => 'valid'], 201, ['Location' => 'https://acme.test/accounts/1']);
            }

            return Http::response(['detail' => 'unexpected '.$request->url()], 500);
        });

        app(AcmeClient::class)->account();

        $this->assertInstanceOf(Request::class, $accountRequest);
        $jws = json_decode($accountRequest->body(), true, flags: JSON_THROW_ON_ERROR);
        $protected = json_decode($this->decodeBase64Url($jws['protected']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(32, strlen($this->decodeBase64Url($protected['jwk']['x'])));
        $this->assertSame(32, strlen($this->decodeBase64Url($protected['jwk']['y'])));
    }

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

    public function test_latest_tls_order_is_selected_by_creation_time_instead_of_uuid(): void
    {
        $domain = Domain::query()->create(['name' => 'latest-order.example.test', 'display_name' => 'Latest order', 'revision' => 1]);
        $older = TlsOrder::query()->create([
            'domain_id' => $domain->id, 'status' => 'failed', 'names' => ['latest-order.example.test'],
            'names_hash' => hash('sha256', 'older'), 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
        ]);
        $newer = TlsOrder::query()->create([
            'domain_id' => $domain->id, 'status' => 'pending', 'names' => ['latest-order.example.test'],
            'names_hash' => hash('sha256', 'newer'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotSame($older->id, $newer->id);
        $this->assertSame($newer->id, $domain->latestTlsOrder()->firstOrFail()->id);
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
        $disabled = DnsCluster::query()->create([
            'name' => 'disabled', 'location' => 'test', 'enabled' => false, 'last_health_status' => 'healthy',
            'api_url' => 'https://disabled-pdns.test', 'api_key' => 'secret', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.disabled.test'], ['hostname' => 'ns2.disabled.test']], 'capacity_zones' => 100,
        ]);
        DnsDeployment::query()->create([
            'domain_id' => $domain->id, 'dns_cluster_id' => $disabled->id, 'status' => 'succeeded',
            'deployed_revision' => $order->dns_revision, 'active_rrsets' => [],
        ]);

        Queue::fake();
        $order->update(['next_poll_at' => now()->subSecond()]);
        $job->handle($client);
        $this->assertSame('publishing', $order->refresh()->status, 'CA validation must not start before every DNS deployment acknowledges the challenge revision.');
        Queue::assertPushed(ReconcileDnsZone::class, fn (ReconcileDnsZone $queued): bool => $queued->domainId === $domain->id);
        $this->assertDatabaseHas('operations', [
            'type' => 'dns.zone_reconcile',
            'status' => 'pending',
        ]);
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

    public function test_lost_finalize_response_reuses_persisted_key_and_request(): void
    {
        Queue::fake();
        config()->set('services.acme.enabled', true);
        config()->set('services.acme.contact_email', 'admin@example.test');
        config()->set('services.acme.directory_url', 'https://acme.test/directory');
        $domain = $this->proxiedDomain();
        (new EnsureManagedCertificates($domain->id))->handle();
        $order = TlsOrder::query()->firstOrFail();
        $finalizeCalls = 0;
        $this->fakeAcme(function () use (&$finalizeCalls, $order): void {
            $finalizeCalls++;
            $this->assertNotNull(TlsOrder::query()->findOrFail($order->id)->csr_der);
            $this->assertNotNull(DB::table('tls_orders')->where('id', $order->id)->value('private_key_ciphertext'));
            throw new RuntimeException('Injected lost finalization response.');
        });
        $job = new IssueManagedCertificate($order->id);
        $client = app(AcmeClient::class);
        $order->update(['available_at' => now()->subSecond()]);
        $job->handle($client);
        $order->update(['status' => 'validating', 'next_poll_at' => now()->subSecond()]);

        try {
            $job->handle($client);
            $this->fail('Injected finalization response loss did not occur');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected lost finalization response.', $exception->getMessage());
        }
        $order->refresh();
        $this->assertSame('validating', $order->status);
        $this->assertNotNull($order->private_key_ciphertext);
        $this->assertNotNull($order->csr_der);
        $this->assertNotSame($order->private_key_ciphertext, DB::table('tls_orders')->where('id', $order->id)->value('private_key_ciphertext'));
        $key = $order->private_key_ciphertext;
        $csr = $order->csr_der;

        $order->update(['available_at' => now()->subSecond(), 'next_poll_at' => now()->subSecond()]);
        $job->handle($client);
        $this->assertSame('finalizing', $order->refresh()->status);
        $this->assertSame($key, $order->private_key_ciphertext);
        $this->assertSame($csr, $order->csr_der);
        $this->assertSame(1, $finalizeCalls);
    }

    public function test_order_becomes_obsolete_when_domain_is_disabled_before_issuance(): void
    {
        Queue::fake();
        $domain = $this->proxiedDomain();
        (new EnsureManagedCertificates($domain->id))->handle();
        $order = TlsOrder::query()->firstOrFail();
        $order->update(['available_at' => now()->subSecond()]);
        $domain->update(['lifecycle_state' => DomainLifecycleState::Disabled, 'disabled_at' => now()]);
        Http::preventStrayRequests();

        (new IssueManagedCertificate($order->id))->handle(app(AcmeClient::class));

        $this->assertSame('obsolete', $order->refresh()->status);
        $this->assertDatabaseCount('tls_certificates', 0);
        $this->assertNull($domain->refresh()->active_tls_certificate_id);
    }

    public function test_domain_disabled_during_ca_download_cannot_activate_certificate(): void
    {
        Queue::fake();
        config()->set('services.acme.enabled', true);
        config()->set('services.acme.contact_email', 'admin@example.test');
        config()->set('services.acme.directory_url', 'https://acme.test/directory');
        $domain = $this->proxiedDomain();
        (new EnsureManagedCertificates($domain->id))->handle();
        $order = TlsOrder::query()->firstOrFail();
        $job = new IssueManagedCertificate($order->id);
        $client = app(AcmeClient::class);
        $this->fakeAcme();
        $order->update(['available_at' => now()->subSecond()]);
        $job->handle($client);
        $order->update(['status' => 'validating', 'next_poll_at' => now()->subSecond()]);
        $job->handle($client);
        $this->assertSame('finalizing', $order->refresh()->status);

        $this->fakeAcme(null, function () use ($domain) {
            $domain->update(['lifecycle_state' => DomainLifecycleState::Disabled, 'disabled_at' => now()]);

            return Http::response(['status' => 'valid', 'certificate' => 'https://acme.test/certificate/1']);
        });
        $order->update(['next_poll_at' => now()->subSecond()]);
        $job->handle($client);

        $this->assertSame('obsolete', $order->refresh()->status);
        $this->assertDatabaseCount('tls_certificates', 0);
        $this->assertNull($domain->refresh()->active_tls_certificate_id);
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

    public function test_reusing_a_managed_certificate_rolls_back_if_operation_persistence_fails(): void
    {
        Queue::fake();
        $domain = $this->proxiedDomain();
        $domain->tlsCertificates()->create([
            'kind' => 'managed', 'status' => 'active', 'certificate_pem' => 'synthetic-unused-pem', 'chain_pem' => '',
            'private_key_ciphertext' => 'synthetic-unused-key', 'names' => ['example.test', '*.example.test'],
            'fingerprint_sha256' => str_repeat('b', 64), 'not_before' => now()->subDay(),
            'expires_at' => now()->addDays(60), 'activated_at' => now(),
        ]);
        $failOnce = true;
        Operation::creating(function (Operation $operation) use (&$failOnce): void {
            if ($failOnce && $operation->type === 'edge.domain_reconcile') {
                $failOnce = false;
                throw new RuntimeException('Injected operation persistence failure.');
            }
        });

        try {
            (new EnsureManagedCertificates($domain->id))->handle();
            $this->fail('The injected operation failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected operation persistence failure.', $exception->getMessage());
        }

        $this->assertSame(1, $domain->refresh()->revision);
        $this->assertNull($domain->active_tls_certificate_id);
        $this->assertDatabaseCount('operations', 0);
        Queue::assertNotPushed(ReconcileEdgeDomain::class);

        (new EnsureManagedCertificates($domain->id))->handle();
        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertNotNull($domain->active_tls_certificate_id);
        $this->assertDatabaseCount('operations', 1);
        Queue::assertPushed(ReconcileEdgeDomain::class);
    }

    public function test_renewal_does_not_complete_a_pending_forced_reissue(): void
    {
        Queue::fake();
        $domain = $this->proxiedDomain();
        $user = User::factory()->create();
        $domain->users()->attach($user);
        $certificate = $domain->tlsCertificates()->create([
            'kind' => 'managed', 'status' => 'active', 'certificate_pem' => 'synthetic-unused-pem', 'chain_pem' => '',
            'private_key_ciphertext' => 'synthetic-unused-key', 'names' => ['example.test', '*.example.test'],
            'fingerprint_sha256' => str_repeat('c', 64), 'not_before' => now()->subDay(),
            'expires_at' => now()->addDays(60), 'activated_at' => now(),
        ]);
        $domain->update(['active_tls_certificate_id' => $certificate->id]);
        $reissueId = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/tls/reissue")
            ->assertAccepted()->json('data.operation_id');
        $renewalId = $this->postJson("/api/domains/{$domain->id}/tls/renew")
            ->assertAccepted()->json('data.operation_id');

        (new EnsureManagedCertificates($domain->id))->handle();

        $this->getJson("/api/operations/{$renewalId}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.queued_order_ids', []);
        $this->getJson("/api/operations/{$reissueId}")->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.finished_at', null);
        $this->assertDatabaseCount('tls_orders', 0);
        Queue::assertNotPushed(IssueManagedCertificate::class);

        $job = new EnsureManagedCertificates($domain->id, true);
        $job->handle();
        $order = TlsOrder::query()->sole();
        $this->getJson("/api/operations/{$reissueId}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.queued_order_ids', [$order->id]);
        Queue::assertPushed(IssueManagedCertificate::class, fn ($queued): bool => $queued->orderId === $order->id);
        $job->handle();
        $this->assertDatabaseCount('tls_orders', 1);
        $this->assertSame([$order->id], Operation::query()->findOrFail($reissueId)->result['queued_order_ids']);
        $this->assertSame([], Operation::query()->findOrFail($renewalId)->result['queued_order_ids']);
        $this->assertSame($certificate->id, $domain->refresh()->active_tls_certificate_id);
        $this->assertSame(1, $domain->revision);
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

        $this->artisan('cdnf:tls:dispatch-maintenance')->assertSuccessful();
        $this->assertCount(2, $admin->notifications()->get());
        $this->assertNotNull($certificate->refresh()->alerted_at);
        $this->assertNotNull($order->refresh()->alerted_at);
        $this->artisan('cdnf:tls:dispatch-maintenance')->assertSuccessful();
        $this->assertCount(2, $admin->notifications()->get());
    }

    public function test_bounded_maintenance_rotates_through_all_eligible_domains(): void
    {
        Queue::fake();
        $eligible = [];
        foreach (['active', 'disabled', 'active', 'unverified', 'dns-only', 'active'] as $index => $state) {
            $name = "maintenance-{$index}.example.test";
            $domain = Domain::query()->create([
                'name' => $name, 'display_name' => $name, 'revision' => 1,
                'lifecycle_state' => $state === 'disabled' ? DomainLifecycleState::Disabled : DomainLifecycleState::Active,
                'nameservers_verified_at' => $state === 'unverified' ? null : now(),
            ]);
            $domain->dnsRecords()->create([
                'type' => 'A', 'name' => $name, 'content' => '8.8.8.8', 'ttl' => 300,
                'mode' => $state === 'dns-only' ? 'dns_only' : 'proxied',
                'origin' => ['host' => '8.8.8.8'], 'content_hash' => hash('sha256', $name),
            ]);
            if ($state === 'active') {
                $eligible[] = $domain->id;
            }
        }

        for ($batch = 0; $batch < 4; $batch++) {
            $expected = match ($batch) {
                0, 2 => array_slice($eligible, 0, 2),
                1 => [$eligible[2]],
                3 => array_slice($eligible, 2),
            };
            Queue::fake();
            $this->artisan('cdnf:tls:dispatch-maintenance', ['--limit' => 2])->assertSuccessful();
            $this->assertSame($expected, Queue::pushed(EnsureManagedCertificates::class)
                ->map(fn (EnsureManagedCertificates $job): int => $job->domainId)->all());
            if ($batch === 0) {
                $eligible[] = $this->proxiedDomain('www.arrival.test', 'arrival.test')->id;
            }
            $this->travel(1)->hours();
        }
    }

    public function test_maintenance_recovers_only_stale_due_nonterminal_orders_with_a_bounded_batch(): void
    {
        Queue::fake();
        $domain = Domain::query()->create(['name' => 'recovery.example.test', 'display_name' => 'Recovery']);
        $orders = [];
        foreach (['pending', 'publishing', 'validating', 'finalizing', 'failed'] as $status) {
            $order = TlsOrder::query()->create([
                'domain_id' => $domain->id, 'status' => $status, 'names' => [$domain->name],
                'names_hash' => hash('sha256', $status),
            ]);
            $order->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();
            $orders[$status] = $order;
        }
        $orders['validating']->forceFill(['next_poll_at' => now()->addHour()])->saveQuietly();
        $orders['finalizing']->forceFill(['updated_at' => now()])->saveQuietly();

        $this->artisan('cdnf:tls:dispatch-maintenance', ['--limit' => 1, '--orders-only' => true])->assertSuccessful();
        $this->assertSame([$orders['pending']->id], Queue::pushed(IssueManagedCertificate::class)
            ->map(fn (IssueManagedCertificate $job): string => $job->orderId)->all());
        Queue::assertNotPushed(EnsureManagedCertificates::class);
        $this->assertSame($orders['pending']->id, (new IssueManagedCertificate($orders['pending']->id))->uniqueId());
    }

    public function test_expired_challenge_cleanup_records_one_revision_and_operation(): void
    {
        Queue::fake();
        $domain = $this->proxiedDomain();
        $order = TlsOrder::query()->create([
            'domain_id' => $domain->id, 'status' => 'validating', 'names' => ['example.test', '*.example.test'],
            'names_hash' => hash('sha256', 'cleanup'), 'dns_revision' => 1,
        ]);
        $expired = $order->challenges()->create([
            'hostname' => 'example.test', 'record_name' => '_acme-challenge.example.test',
            'record_value' => 'synthetic-expired-token', 'status' => 'valid', 'expires_at' => now()->subMinute(),
        ]);
        $live = $order->challenges()->create([
            'hostname' => '*.example.test', 'record_name' => '_acme-challenge.example.test',
            'record_value' => 'synthetic-live-token', 'status' => 'validating', 'expires_at' => now()->addMinutes(30),
        ]);

        $this->artisan('cdnf:tls:dispatch-maintenance')->assertSuccessful();
        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertNotNull($expired->refresh()->cleaned_at);
        $this->assertNull($live->refresh()->cleaned_at);
        $this->assertDatabaseHas('operations', ['type' => 'dns.zone_reconcile', 'status' => 'pending']);
        Queue::assertPushed(ReconcileDnsZone::class, fn ($job): bool => $job->domainId === $domain->id);

        Queue::fake();
        $this->artisan('cdnf:tls:dispatch-maintenance')->assertSuccessful();
        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertDatabaseCount('operations', 1);
        Queue::assertNotPushed(ReconcileDnsZone::class);
    }

    public function test_challenge_cleanup_rolls_back_when_its_operation_cannot_be_written(): void
    {
        Queue::fake();
        $domain = $this->proxiedDomain();
        $order = TlsOrder::query()->create([
            'domain_id' => $domain->id, 'status' => 'validating', 'names' => ['example.test', '*.example.test'],
            'names_hash' => hash('sha256', 'cleanup-rollback'), 'dns_revision' => 1,
        ]);
        $challenge = $order->challenges()->create([
            'hostname' => 'example.test', 'record_name' => '_acme-challenge.example.test',
            'record_value' => 'synthetic-expired-token', 'status' => 'valid', 'expires_at' => now()->subMinute(),
        ]);
        $failOnce = true;
        Operation::creating(function (Operation $operation) use (&$failOnce): void {
            if ($failOnce && $operation->type === 'dns.zone_reconcile') {
                $failOnce = false;
                throw new RuntimeException('Injected cleanup operation failure.');
            }
        });
        try {
            $this->artisan('cdnf:tls:dispatch-maintenance');
            $this->fail('The operation failure must roll back cleanup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected cleanup operation failure.', $exception->getMessage());
        }
        $this->assertSame(1, $domain->refresh()->revision);
        $this->assertNull($challenge->refresh()->cleaned_at);
        $this->assertSame('valid', $challenge->status);
        $this->assertDatabaseCount('operations', 0);
        Queue::assertNotPushed(ReconcileDnsZone::class);

        $this->artisan('cdnf:tls:dispatch-maintenance')->assertSuccessful();
        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertNotNull($challenge->refresh()->cleaned_at);
        $this->assertDatabaseCount('operations', 1);
        Queue::assertPushed(ReconcileDnsZone::class);
    }

    public function test_maintenance_retries_an_undispatched_batch_and_recovers_from_lost_progress(): void
    {
        Queue::fake();
        $first = $this->proxiedDomain();
        $second = $this->proxiedDomain('www.second.test', 'second.test');
        $dispatcher = Bus::getFacadeRoot();
        Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Injected queue failure.'));
        try {
            $this->artisan('cdnf:tls:dispatch-maintenance', ['--limit' => 1]);
            $this->fail('The queue failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected queue failure.', $exception->getMessage());
        } finally {
            Bus::swap($dispatcher);
        }
        $this->travel(1)->hours();
        foreach ([$first->id, $second->id] as $expected) {
            Queue::fake();
            $this->artisan('cdnf:tls:dispatch-maintenance', ['--limit' => 1])->assertSuccessful();
            $this->assertSame([$expected], Queue::pushed(EnsureManagedCertificates::class)
                ->map(fn (EnsureManagedCertificates $job): int => $job->domainId)->all());
            $this->travel(1)->hours();
        }
        $this->artisan('cdnf:tls:dispatch-maintenance', ['--limit' => 1])->assertSuccessful();
        Cache::forget('tls-maintenance-domain-progress');
        $this->travel(1)->hours();
        Queue::fake();
        $this->artisan('cdnf:tls:dispatch-maintenance', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(EnsureManagedCertificates::class, fn ($job): bool => $job->domainId === $first->id);
        Queue::assertNotPushed(EnsureManagedCertificates::class, fn ($job): bool => $job->domainId === $second->id);
    }

    public function test_maintenance_skips_a_domain_scan_held_by_another_invocation(): void
    {
        Queue::fake();
        $domain = $this->proxiedDomain();
        $lock = Cache::lock('tls-maintenance-domain-scan', 300);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('cdnf:tls:dispatch-maintenance')->assertSuccessful();
            Queue::assertNotPushed(EnsureManagedCertificates::class);
        } finally {
            $lock->release();
        }
        $this->artisan('cdnf:tls:dispatch-maintenance')->assertSuccessful();
        Queue::assertPushed(EnsureManagedCertificates::class, fn ($job): bool => $job->domainId === $domain->id);
    }

    public function test_maintenance_does_not_publish_progress_after_losing_its_scan_lease(): void
    {
        Queue::fake();
        $first = $this->proxiedDomain();
        $this->proxiedDomain('www.second.test', 'second.test');
        $dispatcher = Bus::getFacadeRoot();
        $replacement = null;
        Bus::partialMock()->shouldReceive('dispatch')->once()->andReturnUsing(function () use (&$replacement): void {
            $this->travel(301)->seconds();
            $replacement = Cache::lock('tls-maintenance-domain-scan', 300);
            $this->assertTrue($replacement->get());
        });
        try {
            $this->artisan('cdnf:tls:dispatch-maintenance', ['--limit' => 1]);
            $this->fail('The lost scan lease must stop progress publication.');
        } catch (RuntimeException $exception) {
            $this->assertSame('TLS maintenance scan lease was lost; retry the command.', $exception->getMessage());
            $this->assertTrue($replacement->isOwnedByCurrentProcess());
        } finally {
            Bus::swap($dispatcher);
            $replacement?->release();
        }
        $this->travel(1)->hours();
        $this->artisan('cdnf:tls:dispatch-maintenance', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(EnsureManagedCertificates::class, fn ($job): bool => $job->domainId === $first->id);
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

        $finishedAt = $order->finished_at;
        $error = $order->last_error;
        $this->travel(1)->minutes();
        Queue::fake();
        (new IssueManagedCertificate($order->id))->failed(new RuntimeException('A repeated failure callback.'));
        $this->assertSame($revision + 1, $domain->refresh()->revision);
        $this->assertSame($error, $order->refresh()->last_error);
        $this->assertEquals($finishedAt, $order->finished_at);
        $this->assertSame($error, $operation->refresh()->error);
        Queue::assertNotPushed(ReconcileDnsZone::class);
        $this->assertSame(1, Operation::query()->where('type', 'dns.zone_reconcile')->where('input->domain_id', $domain->id)->count());
    }

    public function test_obsolete_order_cleanup_and_receipt_roll_back_and_retry_together(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $domain = $this->proxiedDomain();
        $domain->dnsRecords()->delete();
        $order = TlsOrder::query()->create([
            'domain_id' => $domain->id, 'status' => 'publishing', 'names' => ['example.test', '*.example.test'],
            'names_hash' => hash('sha256', "example.test\0*.example.test"), 'dns_revision' => $domain->revision,
        ]);
        $challenge = $order->challenges()->create([
            'hostname' => 'example.test', 'record_name' => '_acme-challenge.example.test', 'record_value' => 'synthetic-value',
            'status' => 'published', 'expires_at' => now()->addMinutes(10),
        ]);
        $operation = Operation::query()->create([
            'type' => 'tls.managed_certificate', 'status' => 'running',
            'input' => ['domain_id' => $domain->id, 'order_id' => $order->id],
        ]);
        // This test is guarded to SQLite :memory: before migrations or DDL.
        DB::unprepared("CREATE TRIGGER reject_obsolete_receipt BEFORE UPDATE ON operations
            WHEN OLD.type = 'tls.managed_certificate' AND NEW.status = 'failed'
            BEGIN SELECT RAISE(ABORT, 'Injected obsolete receipt failure'); END");
        try {
            (new IssueManagedCertificate($order->id))->handle(app(AcmeClient::class));
            $this->fail('The receipt write failure must propagate.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected obsolete receipt failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_obsolete_receipt');
        }
        $this->assertSame('publishing', $order->refresh()->status);
        $this->assertNull($order->finished_at);
        $this->assertNull($challenge->refresh()->cleaned_at);
        $this->assertSame('running', $operation->refresh()->status);
        $this->assertSame(1, $domain->refresh()->revision);
        Queue::assertNotPushed(ReconcileDnsZone::class);

        (new IssueManagedCertificate($order->id))->handle(app(AcmeClient::class));
        $this->assertSame('obsolete', $order->refresh()->status);
        $this->assertSame('cleaned', $challenge->refresh()->status);
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame($order->last_error, $operation->error);
        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertSame(1, Operation::query()->where('type', 'dns.zone_reconcile')->count());
        Queue::assertPushed(ReconcileDnsZone::class);
        $finishedAt = $order->finished_at;
        Queue::fake();
        $this->travel(1)->minutes();
        (new IssueManagedCertificate($order->id))->handle(app(AcmeClient::class));
        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertEquals($finishedAt, $order->refresh()->finished_at);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function proxiedDomain(string $hostname = 'www.example.test', string $zone = 'example.test'): Domain
    {
        $domain = Domain::query()->create([
            'name' => $zone, 'display_name' => 'Example', 'revision' => 1,
            'lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now(),
        ]);
        $domain->dnsRecords()->create([
            'type' => 'A', 'name' => $hostname, 'content' => '8.8.8.8', 'ttl' => 300, 'mode' => 'proxied',
            'origin' => ['host' => '8.8.8.8'], 'content_hash' => hash('sha256', $hostname),
        ]);

        return $domain;
    }

    private function fakeAcme(?\Closure $finalizeResponse = null, ?\Closure $orderResponse = null): void
    {
        Http::fake(function (Request $request) use ($finalizeResponse, $orderResponse) {
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
            if ($url === 'https://acme.test/finalize/1' && $finalizeResponse !== null) {
                return $finalizeResponse($request);
            }
            if ($url === 'https://acme.test/challenge/1' || $url === 'https://acme.test/finalize/1') {
                return Http::response(['status' => 'processing']);
            }
            if ($url === 'https://acme.test/order/1') {
                return $orderResponse !== null ? $orderResponse($request) : Http::response(['status' => 'valid', 'certificate' => 'https://acme.test/certificate/1']);
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

    private function decodeBase64Url(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        $this->assertNotFalse($decoded);

        return $decoded;
    }
}
