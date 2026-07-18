<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Support\GeoDnsCompiler;
use App\Support\GeoDnsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoDnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_dns_create_read_update_and_preview_are_policy_scoped(): void
    {
        [$user, $domain] = $this->ownedDomain();
        [$other, $otherDomain] = $this->ownedDomain('other.test');
        $payload = $this->payload();
        $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $payload)
            ->assertCreated()->assertJsonPath('data.mode', 'geo_dns')->assertJsonPath('data.geo.countries.IR.0', '203.0.113.30');
        $id = $response->json('data.id');

        $this->actingAs($other)->getJson("/api/domains/{$domain->id}/dns/records/$id/geo")->assertForbidden();
        $this->actingAs($user)->getJson("/api/domains/{$otherDomain->id}/dns/records/$id/geo")->assertForbidden();
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/dns/records/$id/geo")
            ->assertOk()->assertJsonPath('data.continents.EU.0', '203.0.113.20');

        $revision = $domain->refresh()->revision;
        $updated = $payload['geo'];
        $updated['default'] = ['203.0.113.11'];
        $this->actingAs($user)->withHeader('Idempotency-Key', 'f975a630-0993-4cb8-a2e6-d2b6ad61cb13')
            ->putJson("/api/domains/{$domain->id}/dns/records/$id/geo", $updated)
            ->assertOk()->assertJsonPath('data.geo.default.0', '203.0.113.11');
        $this->assertSame($revision + 1, $domain->refresh()->revision);

        $preview = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records/$id/geo/preview", ['ip' => '2001:db8::1'])
            ->assertOk()->assertJsonPath('data.country', null)
            ->assertJsonPath('data.targets.0', '203.0.113.11');
        $this->assertContains($preview->json('data.source'), ['mmdb', 'unknown']);
    }

    public function test_priority_family_bounds_and_dns_only_isolation(): void
    {
        $config = GeoDnsConfig::validate($this->payload()['geo'], 'A', 'example.com');
        $this->assertSame(['203.0.113.30'], GeoDnsConfig::select($config, 'IR', 'AS'));
        $this->assertSame(['203.0.113.20'], GeoDnsConfig::select($config, 'FR', 'EU'));
        $this->assertSame(['203.0.113.10'], GeoDnsConfig::select($config, null, null));
        $lua = GeoDnsCompiler::compile('A', $config);
        $this->assertStringContainsString('countryCode()', $lua);
        $this->assertStringContainsString('continentCode()', $lua);
        $this->assertLessThan(4000, strlen($lua));

        [$user, $domain] = $this->ownedDomain();
        $bad = $this->payload();
        $bad['geo']['default'] = ['2001:db8::1'];
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $bad)->assertUnprocessable();
        $bad = $this->payload();
        $bad['geo']['countries'] = ['QQ' => ['203.0.113.50']];
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $bad)->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", [
            'type' => 'A', 'name' => 'plain', 'content' => '192.0.2.1', 'ttl' => 300,
        ])->assertCreated()->assertJsonPath('data.mode', 'dns_only');
        $this->assertDatabaseCount('dns_records', 1);
    }

    public function test_every_supported_dns_type_accepts_type_safe_geographic_answers(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $cases = [
            'A' => ['192.0.2.1', '192.0.2.2'],
            'AAAA' => ['2001:db8::1', '2001:db8::2'],
            'CNAME' => ['default.example.net', 'ir.example.net'],
            'MX' => ['mail-default.example.net', 'mail-ir.example.net'],
            'TXT' => ['default text', "Iran's regional text"],
            'SRV' => ['sip-default.example.net', 'sip-ir.example.net'],
        ];

        foreach ($cases as $type => [$default, $iran]) {
            $payload = [
                'type' => $type,
                'name' => $type === 'SRV' ? '_sip._tcp.geo' : strtolower($type).'-geo',
                'ttl' => 60,
                'mode' => 'geo_dns',
                'geo' => ['default' => [$default], 'countries' => ['IR' => [$iran]]],
            ];
            if ($type === 'MX') {
                $payload['priority'] = 10;
            }
            if ($type === 'SRV') {
                $payload += ['priority' => 10, 'weight' => 5, 'port' => 5060];
            }

            $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $payload)->assertCreated();
            $this->assertSame($type, $response->json('data.type'));
        }

        $txt = $domain->dnsRecords()->where('type', 'TXT')->firstOrFail();
        $compiled = GeoDnsCompiler::compile('TXT', $txt->geo_config);
        $this->assertStringContainsString("Iran\\\\'s regional text", $compiled);
        $mx = $domain->dnsRecords()->where('type', 'MX')->firstOrFail();
        $this->assertStringContainsString('10 mail-ir.example.net.', GeoDnsCompiler::compile('MX', $mx->geo_config, $mx->priority));

        $this->assertSame(['ns.example.net.'], GeoDnsConfig::validate(['default' => ['ns.example.net']], 'NS', $domain->name)['default']);
        $this->assertSame(['host.example.net.'], GeoDnsConfig::validate(['default' => ['host.example.net']], 'PTR', '2.0.192.in-addr.arpa')['default']);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", [
            'type' => 'CAA', 'name' => 'caa-geo', 'ttl' => 60, 'mode' => 'geo_dns',
            'geo' => ['default' => ['0 issue letsencrypt.org']],
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    private function payload(): array
    {
        return ['type' => 'A', 'name' => 'geo', 'ttl' => 60, 'mode' => 'geo_dns', 'geo' => [
            'default' => ['203.0.113.10'],
            'continents' => ['EU' => ['203.0.113.20']],
            'countries' => ['IR' => ['203.0.113.30']],
        ]];
    }

    private function ownedDomain(string $name = 'example.com'): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'lifecycle_state' => 'pending_verification', 'revision' => 1]);
        $domain->users()->attach($user);

        return [$user, $domain];
    }
}
