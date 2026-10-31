<?php

namespace Tests\Feature;

use App\Models\DnsCluster;
use App\Models\DnsZoneChange;
use App\Models\User;
use App\Support\PowerDnsClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DnsPrimaryTransferTest extends TestCase
{
    use RefreshDatabase;

    private const RRSETS = [
        ['name' => 'example.test.', 'type' => 'SOA', 'ttl' => 300, 'records' => [['content' => 'ns1.t. h.t. 2026110101 3600 600 1209600 300', 'disabled' => false]]],
        ['name' => 'www.example.test.', 'type' => 'A', 'ttl' => 60, 'records' => [['content' => '192.0.2.1', 'disabled' => false]]],
    ];

    public function test_the_first_zone_sets_up_the_transfer_key_and_catalog_and_joins_the_catalog_as_a_primary_zone(): void
    {
        $cluster = $this->cluster();
        $created = [];
        $this->primaryFake($created);

        app(PowerDnsClient::class)->activate($cluster, 'example.test', self::RRSETS, []);

        $sent = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH));
        $this->assertTrue($sent->contains('POST /api/v1/servers/localhost/tsigkeys'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/tsigkeys')
            && $r['name'] === PowerDnsClient::TRANSFER_KEY && $r['key'] === $cluster->transfer_tsig_secret);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/zones')
            && $r['name'] === PowerDnsClient::CATALOG_ZONE && $r['kind'] === 'Producer');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/zones')
            && $r['name'] === 'example.test.' && $r['kind'] === 'Master' && $r['catalog'] === PowerDnsClient::CATALOG_ZONE);
        foreach ([PowerDnsClient::CATALOG_ZONE, 'example.test.'] as $zone) {
            Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), rawurlencode($zone).'/metadata')
                && $r['kind'] === 'TSIG-ALLOW-AXFR' && $r['metadata'] === [PowerDnsClient::TRANSFER_KEY]);
        }
    }

    public function test_each_activation_and_deletion_moves_the_zone_to_a_newer_cursor_position(): void
    {
        $cluster = $this->cluster();
        $created = [];
        $this->primaryFake($created);
        $client = app(PowerDnsClient::class);

        $client->activate($cluster, 'example.test', self::RRSETS, []);
        $first = DnsZoneChange::query()->where('zone', 'example.test')->firstOrFail();
        $this->assertSame(2026110101, $first->serial);
        $this->assertFalse($first->deleted);

        $client->activate($cluster, 'other.test', self::RRSETS, []);
        $client->activate($cluster, 'example.test', self::RRSETS, self::RRSETS);
        $this->assertGreaterThan($first->id, DnsZoneChange::query()->where('zone', 'example.test')->value('id'));
        $this->assertSame(2, DnsZoneChange::query()->count(), 'one row per zone, however often it changes');

        $client->deleteZone($cluster, 'other.test');
        $this->assertTrue(DnsZoneChange::query()->where('zone', 'other.test')->value('deleted'));
    }

    public function test_a_failed_activation_records_no_change(): void
    {
        $cluster = $this->cluster();
        Http::fake(fn () => Http::response(['error' => 'down'], 503));

        try {
            app(PowerDnsClient::class)->activate($cluster, 'example.test', self::RRSETS, []);
        } catch (\Throwable) {
        }

        $this->assertSame(0, DnsZoneChange::query()->count());
    }

    public function test_the_transfer_secret_is_generated_encrypted_and_never_serialized(): void
    {
        $cluster = $this->cluster();

        $this->assertNotEmpty($cluster->transfer_tsig_secret);
        $this->assertNotSame($cluster->transfer_tsig_secret, $cluster->getRawOriginal('transfer_tsig_secret'));
        $this->assertArrayNotHasKey('transfer_tsig_secret', $cluster->toArray());
    }

    private function cluster(): DnsCluster
    {
        return DnsCluster::query()->create([
            'name' => 'primary', 'location' => 'control', 'enabled' => true, 'api_url' => 'http://pdns-primary.test',
            'api_key' => 'private-test-key', 'server_id' => 'localhost', 'transfer_endpoint' => '192.0.2.10:5300',
            'nameservers' => [['hostname' => 'ns1.cdnf.test'], ['hostname' => 'ns2.cdnf.test']],
        ]);
    }

    /** A primary that knows the zones and keys created through it. */
    private function primaryFake(array &$created): void
    {
        Http::fake(function (Request $request) use (&$created) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
            if ($request->method() === 'POST' && str_ends_with($path, '/tsigkeys')) {
                $created['tsig'] = true;

                return Http::response([], 201);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/zones')) {
                $created[$request['name']] = true;

                return Http::response([], 201);
            }
            if ($request->method() === 'GET' && str_contains($path, '/tsigkeys/')) {
                return isset($created['tsig']) ? Http::response(['name' => PowerDnsClient::TRANSFER_KEY]) : Http::response([], 404);
            }
            if ($request->method() === 'GET' && str_contains($path, '/zones/')) {
                $zone = substr($path, strrpos($path, '/zones/') + 7);

                return isset($created[$zone])
                    ? Http::response(['kind' => 'Master', 'catalog' => PowerDnsClient::CATALOG_ZONE, 'rrsets' => self::RRSETS])
                    : Http::response([], 404);
            }

            return Http::response([], 204);
        });
    }

    public function test_only_one_primary_can_be_enabled_and_the_endpoint_must_be_an_address_and_port(): void
    {
        $admin = User::factory()->admin()->create();
        $first = $this->cluster();
        $second = DnsCluster::query()->create([
            'name' => 'second', 'location' => 'x', 'enabled' => false, 'api_url' => 'http://other.test', 'api_key' => 'private-test-key',
            'transfer_endpoint' => '192.0.2.11:5300', 'nameservers' => [['hostname' => 'a.test'], ['hostname' => 'b.test']], 'last_health_status' => 'healthy',
        ]);
        Queue::fake();

        $this->actingAs($admin)->postJson("/api/admin/dns/clusters/{$second->id}/enable")->assertConflict();
        $this->assertTrue($first->refresh()->enabled);
        $this->expectException(QueryException::class);
        $second->update(['enabled' => true]);
    }

    public function test_the_transfer_endpoint_rejects_a_hostname_and_accepts_ipv4_and_ipv6(): void
    {
        $admin = User::factory()->admin()->create();
        Queue::fake();
        $base = ['location' => 'x', 'api_url' => 'http://p.test', 'api_key' => 'private-test-key', 'nameservers' => [['hostname' => 'a.test'], ['hostname' => 'b.test']]];

        $this->actingAs($admin)->postJson('/api/admin/dns/clusters', [...$base, 'name' => 'host', 'transfer_endpoint' => 'primary.example.test:5300'])
            ->assertUnprocessable()->assertJsonValidationErrors('transfer_endpoint');
        $this->actingAs($admin)->postJson('/api/admin/dns/clusters', [...$base, 'name' => 'v4', 'transfer_endpoint' => '192.0.2.10:5300'])->assertAccepted();
        $this->actingAs($admin)->postJson('/api/admin/dns/clusters', [...$base, 'name' => 'v6', 'transfer_endpoint' => '[2001:db8::10]:5300'])->assertAccepted();
    }
}
