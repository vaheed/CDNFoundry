<?php

namespace Tests\Feature;

use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\TestDnsCluster;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\DnsRecordData;
use App\Support\PowerDnsClient;
use App\Support\PowerDnsZone;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DnsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_cluster_without_exposing_encrypted_api_key(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain();
        $response = $this->actingAs($admin)->postJson('/api/admin/dns/clusters', $this->clusterData())
            ->assertAccepted()->assertJsonMissing(['api_key' => 'private-test-key']);

        $cluster = DnsCluster::findOrFail($response->json('data.id'));
        $this->assertNotSame('private-test-key', $cluster->getRawOriginal('api_key'));
        $this->assertSame('private-test-key', $cluster->api_key);
        $this->assertFalse($cluster->enabled);
        Queue::assertPushed(TestDnsCluster::class);
        Queue::assertNotPushed(ReconcileDnsZone::class);
        $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$cluster->id}/enable")->assertConflict();
        $cluster->update(['last_health_status' => 'healthy']);
        $response = $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$cluster->id}/enable")->assertAccepted()->assertJsonPath('data.enabled', true);
        Queue::assertPushed(ReconcileAllDnsZones::class);
        (new ReconcileAllDnsZones($response->json('operation_id')))->handle();
        Queue::assertPushed(ReconcileDnsZone::class, fn ($job): bool => $job->domainId === $domain->id);
    }

    public function test_reconciliation_renders_and_activates_latest_zone_deterministically(): void
    {
        $domain = $this->domain();
        $domain->dnsRecords()->create(DnsRecordData::validate([
            'type' => 'A', 'name' => 'www', 'content' => '192.0.2.10', 'ttl' => 300,
        ], $domain->name));
        $cluster = DnsCluster::query()->create($this->clusterData());
        $this->platformIdentity();
        $published = null;
        Http::fake(function (Request $request) use (&$published) {
            if ($request->method() === 'GET') {
                return $published === null ? Http::response([], 404) : Http::response(['rrsets' => $published]);
            }

            if ($request->method() === 'PATCH') {
                $published = collect($request->data()['rrsets'])->where('changetype', 'REPLACE')->map(fn (array $rrset): array => collect($rrset)->except('changetype')->all())->values()->all();
            }

            return Http::response([], $request->method() === 'POST' ? 201 : 204);
        });

        (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));

        $deployment = DnsDeployment::query()->whereBelongsTo($domain)->whereBelongsTo($cluster, 'cluster')->firstOrFail();
        $this->assertSame('succeeded', $deployment->status);
        $this->assertSame($domain->revision, $deployment->deployed_revision);
        $this->assertNotEmpty($deployment->active_rrsets);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && collect($request->data()['rrsets'] ?? [])->contains(fn (array $rrset): bool => ($rrset['type'] ?? null) === 'A' && ($rrset['records'][0]['content'] ?? null) === '192.0.2.10'));
    }

    public function test_zone_transfer_authorization_precedes_catalog_publication(): void
    {
        $domain = $this->domain();
        $cluster = DnsCluster::query()->create($this->clusterData());
        $this->platformIdentity();
        $requests = [];
        $published = null;
        Http::fake(function (Request $request) use (&$requests, &$published) {
            $requests[] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data()];
            if ($request->method() === 'GET') {
                return $published === null ? Http::response([], 404) : Http::response(['rrsets' => $published, 'serial' => 1]);
            }
            if ($request->method() === 'PATCH') {
                $published = collect($request->data()['rrsets'])->where('changetype', 'REPLACE')
                    ->map(fn (array $rrset): array => collect($rrset)->except('changetype')->all())->values()->all();
            }

            return Http::response([], $request->method() === 'POST' ? 201 : 204);
        });

        (new PowerDnsClient)->activate($cluster, $domain->name, PowerDnsZone::render($domain), []);

        $zoneCreate = collect($requests)->search(fn (array $item): bool => $item['method'] === 'POST'
            && str_ends_with($item['url'], '/zones') && ($item['data']['name'] ?? null) === $domain->name . '.');
        $transferPermission = collect($requests)->search(fn (array $item): bool => $item['method'] === 'POST'
            && str_ends_with($item['url'], '/zones/' . $domain->name . '.' . '/metadata')
            && ($item['data']['kind'] ?? null) === 'TSIG-ALLOW-AXFR');
        $catalogPublication = collect($requests)->search(fn (array $item): bool => $item['method'] === 'PUT'
            && str_ends_with($item['url'], '/zones/' . $domain->name . '.')
            && ($item['data']['catalog'] ?? null) === PowerDnsClient::CATALOG_ZONE);
        $rrsetWrite = collect($requests)->search(fn (array $item): bool => $item['method'] === 'PATCH'
            && str_ends_with($item['url'], '/zones/' . $domain->name . '.'));

        $this->assertNotFalse($zoneCreate);
        $this->assertNotFalse($transferPermission);
        $this->assertNotFalse($catalogPublication);
        $this->assertNotFalse($rrsetWrite);
        $this->assertArrayNotHasKey('catalog', $requests[$zoneCreate]['data']);
        $this->assertLessThan($catalogPublication, $transferPermission, 'TSIG permission must exist before catalog membership is published');
        $this->assertLessThan($rrsetWrite, $catalogPublication, 'RRsets are written only after the authorized zone is published');
    }

    public function test_failed_target_keeps_previous_valid_snapshot_and_visible_error(): void
    {
        $domain = $this->domain();
        $cluster = DnsCluster::query()->create($this->clusterData());
        $this->platformIdentity();
        $previous = [['name' => 'example.com.', 'type' => 'A', 'ttl' => 300, 'records' => [['content' => '192.0.2.1', 'disabled' => false]]]];
        DnsDeployment::query()->create([
            'domain_id' => $domain->id, 'dns_cluster_id' => $cluster->id, 'desired_revision' => 1,
            'deployed_revision' => 1, 'status' => 'succeeded', 'active_checksum' => str_repeat('a', 64), 'active_rrsets' => $previous,
        ]);
        $domain->update(['revision' => 2]);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        try {
            (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));
            $this->fail('The failed cluster should make the job retryable.');
        } catch (\RuntimeException) {
            // Expected: queue retries remain available while deployment state records the target failure.
        }

        $deployment = DnsDeployment::firstOrFail();
        $this->assertSame('failed', $deployment->status);
        $this->assertSame(1, $deployment->deployed_revision);
        $this->assertEquals($previous, $deployment->active_rrsets);
        $this->assertStringContainsString('503', $deployment->last_error);
    }

    public function test_read_back_mismatch_attempts_rollback_and_keeps_previous_deployment(): void
    {
        $domain = $this->domain();
        $cluster = DnsCluster::query()->create($this->clusterData());
        $this->platformIdentity();
        $previous = [['name' => 'example.com.', 'type' => 'A', 'ttl' => 300, 'records' => [['content' => '192.0.2.1', 'disabled' => false]]]];
        DnsDeployment::query()->create([
            'domain_id' => $domain->id, 'dns_cluster_id' => $cluster->id, 'desired_revision' => 1,
            'deployed_revision' => 1, 'status' => 'succeeded', 'active_checksum' => str_repeat('a', 64), 'active_rrsets' => $previous,
        ]);
        $domain->update(['revision' => 2]);
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['rrsets' => $previous, 'kind' => 'Master', 'catalog' => PowerDnsClient::CATALOG_ZONE, 'soa_edit_api' => 'INCREASE']) : Http::response([], 204));

        try {
            (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));
            $this->fail('A mismatched runtime must not be acknowledged.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('read-back', $exception->getMessage());
        }

        $deployment = DnsDeployment::query()->firstOrFail();
        $this->assertSame(1, $deployment->deployed_revision);
        $this->assertEquals($previous, $deployment->active_rrsets);
        $this->assertSame('failed', $deployment->status);
        Http::assertSentCount(5); // existence read, candidate patch, read-back, rollback patch, rollback read-back
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && collect($request->data()['rrsets'] ?? [])->contains(fn (array $rrset): bool => ($rrset['type'] ?? null) === 'A' && ($rrset['records'][0]['content'] ?? null) === '192.0.2.1'));
    }

    public function test_an_outage_keeps_the_operation_running_until_a_retry_heals_it(): void
    {
        $domain = $this->domain();
        DnsCluster::query()->create($this->clusterData());
        $this->platformIdentity();
        $operation = Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
        $outage = true;
        $published = null;
        Http::fake(function (Request $request) use (&$outage, &$published) {
            if ($outage) {
                return Http::response(['error' => 'unavailable'], 503);
            }
            if ($request->method() === 'GET') {
                return $published === null ? Http::response([], 404) : Http::response(['rrsets' => $published]);
            }
            if ($request->method() === 'PATCH') {
                $published = collect($request->data()['rrsets'])->where('changetype', 'REPLACE')->map(fn (array $rrset): array => collect($rrset)->except('changetype')->all())->values()->all();
            }

            return Http::response([], $request->method() === 'POST' ? 201 : 204);
        });

        foreach ([1, 2, 3] as $attempt) {
            try {
                (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));
                $this->fail('The outage must make the job retryable.');
            } catch (\RuntimeException) {
                $this->assertSame('running', $operation->refresh()->status, "attempt {$attempt} must not mark the operation failed");
                $this->assertStringStartsWith('Retrying:', $operation->error);
            }
        }

        $outage = false;
        (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));

        $this->assertSame('succeeded', $operation->refresh()->status);
        $this->assertNull($operation->error);
        $this->assertSame('succeeded', DnsDeployment::query()->firstOrFail()->status);
    }

    public function test_a_deleted_domain_ends_the_job_without_retrying(): void
    {
        $domain = $this->domain();
        $operation = Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
        $domain->delete();

        (new ReconcileDnsZone($domain->id))->handle(app(PowerDnsClient::class));

        $this->assertSame('failed', $operation->refresh()->status);
    }

    public function test_the_operation_fails_only_when_the_retry_window_ends(): void
    {
        $domain = $this->domain();
        $operation = Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
        $job = new ReconcileDnsZone($domain->id);

        $this->assertSame(0, $job->tries);
        $this->assertSame([10, 30, 60, 120, 300], $job->backoff());
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);
        $this->assertSame('pending', $operation->refresh()->status);

        $job->failed(new \RuntimeException('retry window ended'));

        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame('retry window ended', $operation->error);
    }

    public function test_drift_check_repairs_a_missing_zone_a_changed_zone_and_a_failed_deployment_but_not_an_outage(): void
    {
        Queue::fake();
        $this->platformIdentity();
        $cluster = DnsCluster::query()->create($this->clusterData());
        $deployed = function (string $name, string $status = 'succeeded') use ($cluster): Domain {
            $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'lifecycle_state' => 'active', 'revision' => 1]);
            $rrsets = PowerDnsZone::render($domain);
            DnsDeployment::query()->create([
                'domain_id' => $domain->id, 'dns_cluster_id' => $cluster->id, 'desired_revision' => 1, 'deployed_revision' => 1,
                'status' => $status, 'active_checksum' => hash('sha256', json_encode($rrsets)), 'active_rrsets' => $rrsets,
            ]);

            return $domain;
        };
        $healthy = $deployed('healthy.example');
        $missing = $deployed('missing.example');
        $changed = $deployed('changed.example');
        $failed = $deployed('failed.example', 'failed');
        $unreachable = $deployed('unreachable.example');
        Http::fake(function (Request $request) use ($healthy, $changed) {
            if (str_contains($request->url(), 'missing.example')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), 'unreachable.example')) {
                return Http::response(['error' => 'down'], 503);
            }
            $domain = str_contains($request->url(), 'changed.example') ? $changed : $healthy;
            $rrsets = PowerDnsZone::render($domain);
            if ($domain->is($changed)) {
                $rrsets[0]['records'][0]['content'] .= ' tampered';
            }

            return Http::response(['rrsets' => $rrsets]);
        });

        $this->artisan('cdnf:dns:repair-drift')->assertSuccessful();

        $queued = fn (Domain $domain): bool => Queue::pushed(ReconcileDnsZone::class, fn ($job): bool => $job->domainId === $domain->id)->isNotEmpty();
        $this->assertFalse($queued($healthy));
        $this->assertTrue($queued($missing));
        $this->assertTrue($queued($changed));
        $this->assertTrue($queued($failed));
        $this->assertFalse($queued($unreachable), 'an unreachable host is not drift');
        $this->assertNotNull(DnsDeployment::query()->where('domain_id', $healthy->id)->value('verified_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'dns.drift_repair_queued', 'subject_id' => (string) $missing->id]);
        $this->assertDatabaseCount('operations', 3);
    }

    public function test_manual_reconcile_is_coalesced_to_one_pending_operation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $domain = $this->domain();
        $domain->users()->attach($user);

        $first = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/reconcile")->assertAccepted();
        $second = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/reconcile")->assertAccepted();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('operations', 1);
        Queue::assertPushed(ReconcileDnsZone::class, 1);
    }

    public function test_reconciliation_unique_lock_releases_at_processing_for_one_follow_up_revision(): void
    {
        $job = new ReconcileDnsZone(123);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame('123', $job->uniqueId());
        $this->assertSame(300, $job->uniqueFor);
    }

    private function domain(): Domain
    {
        return Domain::query()->create(['name' => 'example.com', 'display_name' => 'example.com', 'lifecycle_state' => 'active', 'revision' => 1]);
    }

    private function clusterData(): array
    {
        return [
            'name' => 'dns-eu-1', 'location' => 'eu-test', 'enabled' => true,
            'api_url' => 'http://pdns.test', 'transfer_endpoint' => '192.0.2.10:5300', 'api_key' => 'private-test-key', 'server_id' => 'localhost',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']], 'capacity_zones' => 1000,
        ];
    }

    private function platformIdentity(): void
    {
        PlatformDnsSetting::query()->create([
            'id' => 1, 'platform_domain' => 'cdnf.test', 'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [['hostname' => 'ns1.cdnf.test', 'ipv4' => '192.0.2.1', 'ipv6' => '2001:db8::1'], ['hostname' => 'ns2.cdnf.test', 'ipv4' => '192.0.2.2', 'ipv6' => '2001:db8::2']],
            'soa_primary' => 'ns1.cdnf.test', 'soa_mailbox' => 'hostmaster.cdnf.test', 'soa_refresh' => 3600,
            'soa_retry' => 600, 'soa_expire' => 604800, 'soa_minimum_ttl' => 300, 'default_ttl' => 300,
        ]);
    }
}
