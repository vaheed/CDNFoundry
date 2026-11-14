<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyRollbackSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_restores_customer_fields_only_and_rolls_back_waf_and_tls_mode(): void
    {
        [$user, $domain] = $this->domainWithRevision($this->origin('8.8.8.8', platform: true), waf: 'off', tlsMode: 'disabled');
        $domain->update(['waf_profile' => 'strict', 'tls_mode' => 'managed']);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => 1])->assertAccepted();

        $record = $domain->dnsRecords()->where('name', 'www.rollback.test')->sole();
        foreach (['private_allowlist', 'blocked_networks', 'blocked_addresses'] as $key) {
            $this->assertArrayNotHasKey($key, $record->origin);
            $this->assertArrayNotHasKey($key, $record->origin['backup'] ?? []);
        }
        $this->assertSame('8.8.8.8', $record->origin['host']);
        $this->assertSame('off', $domain->refresh()->waf_profile);
        $this->assertSame('disabled', $domain->tls_mode);
    }

    public function test_rollback_refuses_an_origin_that_current_policy_prohibits(): void
    {
        [$user, $domain] = $this->domainWithRevision($this->origin('127.0.0.1'));
        $revision = $domain->revision;

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => 1])->assertUnprocessable();

        $this->assertSame($revision, $domain->refresh()->revision);
        $this->assertSame(0, $domain->dnsRecords()->where('name', 'www.rollback.test')->count());
    }

    public function test_rollback_refuses_a_name_taken_by_a_dns_only_address_and_keeps_other_types(): void
    {
        [$user, $domain] = $this->domainWithRevision($this->origin('8.8.8.8'));
        $domain->dnsRecords()->create(['type' => 'TXT', 'name' => 'www.rollback.test', 'content' => 'keep', 'content_hash' => hash('sha256', 'keep'), 'ttl' => 300, 'mode' => 'dns_only']);
        $address = $domain->dnsRecords()->create(['type' => 'A', 'name' => 'www.rollback.test', 'content' => '1.1.1.1', 'content_hash' => hash('sha256', '1.1.1.1'), 'ttl' => 300, 'mode' => 'dns_only']);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => 1])->assertConflict();
        $this->assertSame(2, $domain->dnsRecords()->where('name', 'www.rollback.test')->where('mode', 'dns_only')->count());

        $address->delete();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => 1])->assertAccepted();
        $this->assertSame(1, $domain->dnsRecords()->where('name', 'www.rollback.test')->where('type', 'TXT')->count());
        $this->assertSame(1, $domain->dnsRecords()->where('name', 'www.rollback.test')->where('mode', 'proxied')->count());
    }

    private function domainWithRevision(array $origin, string $waf = 'off', string $tlsMode = 'managed'): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'rollback.test', 'display_name' => 'Rollback', 'lifecycle_state' => 'active', 'revision' => 5, 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user);
        EdgeRevision::query()->create([
            'domain_id' => $domain->id, 'revision' => 1, 'checksum' => str_repeat('d', 64), 'status' => 'validated',
            'snapshot' => [
                'settings' => ['enabled' => true, 'redirect_https' => true, 'http_versions' => ['1.1', '2'], 'retry_count' => 1, 'maintenance' => null],
                'cache' => ['enabled' => true, 'profile' => ['ignored' => true], 'epoch' => 9],
                'waf' => ['name' => $waf], 'tls' => ['mode' => $tlsMode],
                'hostnames' => [['hostname' => 'www.rollback.test', 'type' => 'A', 'ttl' => 300, 'origin' => $origin]],
            ],
        ]);

        return [$user, $domain];
    }

    private function origin(string $host, bool $platform = false): array
    {
        $origin = [
            'host' => $host, 'port' => 80, 'scheme' => 'http', 'host_header' => 'www.rollback.test', 'sni' => null, 'verify_tls' => false,
            'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => null,
            'backup' => null, 'failover' => null,
        ];
        if ($platform) {
            $origin += ['private_allowlist' => ['10.0.0.0/8'], 'blocked_networks' => ['127.0.0.0/8'], 'blocked_addresses' => ['1.2.3.4']];
        }

        return $origin;
    }
}
