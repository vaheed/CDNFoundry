<?php

namespace Tests\Feature;

use App\Actions\EvaluateEndpointPublication;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use App\Models\EdgeProbeResult;
use App\Models\PlatformDnsSetting;
use App\Support\EdgeCertificateAuthority;
use App\Support\PlatformDnsZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PopFailStaticTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_heartbeats_never_withdraw_a_pop_from_dns(): void
    {
        $pool = $this->pool();
        [$first] = $this->endpoint($pool, 'first', '198.51.100.11');
        [$second] = $this->endpoint($pool, 'second', '198.51.100.12');
        EvaluateEndpointPublication::execute();
        $this->assertSame(['198.51.100.11', '198.51.100.12'], $this->published($pool));

        $first->edge->update(['last_heartbeat_at' => now()->subDays(3)]);
        $second->edge->update(['last_heartbeat_at' => now()->subDays(3)]);

        $this->assertSame(['198.51.100.11', '198.51.100.12'], $this->published($pool), 'a silent control path must not empty the pool');
        $this->assertFalse($pool->fresh()->dns_fail_static);
    }

    public function test_a_drain_disable_or_withdrawal_removes_the_pop_but_never_empties_through_automatic_signals(): void
    {
        $pool = $this->pool();
        [$first] = $this->endpoint($pool, 'first', '198.51.100.21');
        [$second] = $this->endpoint($pool, 'second', '198.51.100.22');
        EvaluateEndpointPublication::execute();

        $first->edge->update(['drained' => true]);
        $this->assertSame(['198.51.100.22'], $this->published($pool));

        // The only remaining PoP reports itself not ready: the last answer stays.
        $second->edge->update(['capacity' => ['listener_ready' => false]]);
        $this->assertSame(['198.51.100.22'], $this->published($pool));
        $this->assertTrue($pool->fresh()->dns_fail_static, 'the pool is flagged so monitoring alerts');

        $second->edge->update(['capacity' => ['listener_ready' => true]]);
        EvaluateEndpointPublication::execute();
        $this->assertFalse($pool->fresh()->dns_fail_static);
    }

    public function test_a_failed_probe_quorum_withdraws_one_pop_but_a_lone_observer_cannot(): void
    {
        $pool = $this->pool();
        [$target] = $this->endpoint($pool, 'target', '198.51.100.31');
        $this->endpoint($pool, 'other', '198.51.100.32');
        $observers = [$this->observer(), $this->observer(), $this->observer()];
        EvaluateEndpointPublication::execute();

        $this->probe($observers[0], $target, '198.51.100.31', false);
        $this->assertContains('198.51.100.31', $this->published($pool), 'one vantage point is not a quorum');

        $this->probe($observers[1], $target, '198.51.100.31', false);
        $this->assertNotContains('198.51.100.31', $this->published($pool));

        $this->probe($observers[2], $target, '198.51.100.31', true);
        $this->assertNotContains('198.51.100.31', $this->published($pool), '2 of 3 failures is still a majority');

        EdgeProbeResult::query()->where('observer_edge_id', $observers[1]->id)->update(['ok' => true]);
        $this->assertContains('198.51.100.31', $this->published($pool), 'recovery republishes the PoP');
    }

    public function test_expired_probe_results_do_not_count(): void
    {
        $pool = $this->pool();
        [$target] = $this->endpoint($pool, 'target', '198.51.100.41');
        $this->endpoint($pool, 'other', '198.51.100.42');
        foreach ([$this->observer(), $this->observer()] as $observer) {
            $this->probe($observer, $target, '198.51.100.41', false);
        }
        EdgeProbeResult::query()->update(['checked_at' => now()->subHour()]);

        $this->assertContains('198.51.100.41', $this->published($pool));
    }

    public function test_the_platform_zone_renders_only_published_endpoints_and_never_an_empty_set(): void
    {
        PlatformDnsSetting::query()->updateOrCreate(['id' => 1], [
            'platform_domain' => 'platform.test', 'proxy_hostname' => 'edge.platform.test', 'soa_primary' => 'ns1.platform.test',
            'soa_mailbox' => 'hostmaster.platform.test', 'default_ttl' => 60, 'soa_refresh' => 3600, 'soa_retry' => 600,
            'soa_expire' => 86400, 'soa_minimum_ttl' => 60, 'nameservers' => [['hostname' => 'ns1.platform.test', 'ipv4' => '203.0.113.1', 'ipv6' => null]],
            'cluster_targets' => [], 'revision' => 1,
        ]);
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $this->endpoint($pool, 'zone', '198.51.100.51');
        $global = fn (): array => collect(PlatformDnsZone::render(PlatformDnsSetting::query()->findOrFail(1)))
            ->filter(fn (array $set): bool => $set['type'] === 'A' && str_contains($set['name'], '.global.'))
            ->flatMap(fn (array $set): array => array_column($set['records'], 'content'))->all();

        $this->assertSame(['198.51.100.51'], $global());

        Edge::query()->update(['last_heartbeat_at' => now()->subWeek(), 'capacity' => ['listener_ready' => false]]);

        $this->assertSame(['198.51.100.51'], $global(), 'the pool still answers while its only PoP looks down');
    }

    public function test_probe_endpoints_list_peers_and_accept_only_known_targets(): void
    {
        $pool = $this->pool();
        [$target] = $this->endpoint($pool, 'peer', '198.51.100.61');
        $observer = $this->observer();
        $headers = $this->identity($observer);

        $targets = $this->withHeaders($headers)->getJson('/edge/v1/probes/targets')->assertOk();
        $this->assertContains('198.51.100.61', array_column($targets->json('data.targets'), 'address'));

        $this->withHeaders($headers)->postJson('/edge/v1/probes', ['results' => [
            ['edge_id' => $target->edge_id, 'address' => '198.51.100.61', 'ok' => false, 'detail' => 'connect timeout'],
            ['edge_id' => $target->edge_id, 'address' => '203.0.113.250', 'ok' => false],
        ]])->assertOk()->assertJsonPath('data.accepted', 1);
        $this->assertDatabaseCount('edge_probe_results', 1);
    }

    private function pool(): EdgePool
    {
        return EdgePool::query()->where('kind', 'shared')->firstOrFail();
    }

    /** @return list<string> */
    private function published(EdgePool $pool): array
    {
        EvaluateEndpointPublication::execute();

        return EdgePoolEndpoint::query()->where('edge_pool_id', $pool->id)->where('dns_published', true)->orderBy('ipv4')->pluck('ipv4')->all();
    }

    /** @return array{0: EdgePoolEndpoint} */
    private function endpoint(EdgePool $pool, string $name, string $address): array
    {
        $edge = Edge::query()->create([
            'name' => $name, 'country_code' => 'DE', 'continent_code' => 'EU', 'management_ipv4' => '203.0.113.'.random_int(2, 250),
            'enabled' => true, 'drained' => false, 'registered_at' => now(), 'last_heartbeat_at' => now(), 'capacity' => ['listener_ready' => true],
        ]);
        $edge->cells()->create(['slot' => 1, 'name' => 'cell-01', 'edge_pool_id' => $pool->id, 'status' => 'ready']);
        $endpoint = $pool->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => $address, 'gateway_state' => 'ready', 'revision' => 1, 'gateway_revision' => 1]);

        return [$endpoint->load('edge')];
    }

    private function observer(): Edge
    {
        return Edge::query()->create([
            'name' => 'observer-'.Str::random(5), 'country_code' => 'US', 'continent_code' => 'NA', 'management_ipv4' => '203.0.113.'.random_int(2, 250),
            'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now(), 'capacity' => ['listener_ready' => true],
        ]);
    }

    private function probe(Edge $observer, EdgePoolEndpoint|Edge $target, string $address, bool $ok): void
    {
        EdgeProbeResult::query()->updateOrCreate(
            ['observer_edge_id' => $observer->id, 'target_edge_id' => $target instanceof Edge ? $target->id : $target->edge_id, 'address' => $address],
            ['ok' => $ok, 'checked_at' => now()],
        );
    }

    private function identity(Edge $edge): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $edge->id], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrPem);
        $signed = EdgeCertificateAuthority::sign($csrPem, $edge->id);
        $edge->update(['identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at']]);

        return ['X-Edge-Proxy-Secret' => config('edge.proxy_secret'), 'X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $signed['serial'], 'X-Edge-Certificate-Pem' => rawurlencode($signed['certificate'])];
    }
}
