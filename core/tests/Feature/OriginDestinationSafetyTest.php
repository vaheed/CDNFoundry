<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Operation;
use App\Models\User;
use App\Support\OriginData;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OriginDestinationSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('deniedOrigins')]
    public function test_denied_origins_cannot_replace_primary_or_backup(string $address, array $settings, string $role): void
    {
        Queue::fake();
        if ($settings !== []) {
            app(PlatformSettings::class)->update('origin_safety', $settings);
        }
        $owner = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'destination.example', 'display_name' => 'Destination',
            'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
        $domain->users()->attach($owner);
        $original = $this->origin('8.8.8.8');
        $record = $domain->dnsRecords()->create(['type' => 'A', 'mode' => 'proxied', 'name' => 'www.destination.example',
            'content' => '8.8.8.8', 'content_hash' => hash('sha256', '8.8.8.8'), 'ttl' => 60, 'origin' => $original]);
        $input = $role === 'primary' ? $this->origin($address) : $original + [
            'backup' => $this->origin($address), 'failover' => [
                'failure_threshold' => 2, 'recovery_threshold' => 2, 'hold_down_seconds' => 10, 'failback_delay_seconds' => 10,
            ],
        ];
        $this->actingAs($owner)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $input)
            ->assertUnprocessable()->assertJsonValidationErrors($role === 'primary' ? 'host' : 'backup.host');
        $this->assertSame($original, $record->refresh()->origin);
        $this->assertSame(1, $domain->refresh()->revision);
        $this->assertSame(0, Operation::query()->where('type', 'edge.domain_reconcile')->count());
    }

    public function test_control_plane_address_cannot_be_an_origin_even_without_an_edge_or_endpoint_row(): void
    {
        // Regression: the stage control host accepted a proxied origin on its own public address,
        // and the edge health probes reached the control plane. APP_URL is the control address.
        Queue::fake();
        config(['app.url' => 'https://8.8.4.4']);
        $owner = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'control-origin.example', 'display_name' => 'Control origin',
            'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
        $domain->users()->attach($owner);
        $original = $this->origin('1.1.1.1');
        $record = $domain->dnsRecords()->create(['type' => 'A', 'mode' => 'proxied', 'name' => 'www.control-origin.example',
            'content' => '1.1.1.1', 'content_hash' => hash('sha256', '1.1.1.1'), 'ttl' => 60, 'origin' => $original]);

        $this->actingAs($owner)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $this->origin('8.8.4.4'))
            ->assertUnprocessable()->assertJsonValidationErrors('host');
        $this->assertSame($original, $record->refresh()->origin);
        $this->assertSame(0, Operation::query()->where('type', 'edge.domain_reconcile')->count());
    }

    public function test_only_the_explicit_origin_port_set_is_accepted(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'ports.example', 'display_name' => 'Ports',
            'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
        $domain->users()->attach($owner);
        $record = $domain->dnsRecords()->create(['type' => 'A', 'mode' => 'proxied', 'name' => 'www.ports.example',
            'content' => '8.8.8.8', 'content_hash' => hash('sha256', '8.8.8.8'), 'ttl' => 60, 'origin' => $this->origin('8.8.8.8')]);

        foreach ([22, 25, 3306, 6379, 9200, 65535] as $port) {
            $this->actingAs($owner)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", ['port' => $port] + $this->origin('8.8.8.8'))
                ->assertUnprocessable()->assertJsonValidationErrors('port');
        }
        $this->actingAs($owner)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", ['port' => 8443] + $this->origin('8.8.8.8'))->assertAccepted();
    }

    public function test_the_runtime_and_the_agent_allow_exactly_the_ports_the_control_plane_does(): void
    {
        $lua = file_get_contents(base_path('../docker/openresty/runtime.lua'));
        $go = file_get_contents(base_path('../edge-agent/main.go'));
        preg_match('/for _, port in ipairs\(\{([^}]*)\}\)/', $lua, $luaMatch);
        preg_match('/var allowedOriginPorts = map\[int\]bool\{([^}]*)\}/', $go, $goMatch);
        $fromLua = array_map('intval', array_map('trim', explode(',', $luaMatch[1] ?? '')));
        $fromGo = array_map(fn (string $entry): int => (int) $entry, array_map('trim', explode(',', $goMatch[1] ?? '')));
        $expected = OriginData::ALLOWED_PORTS;
        sort($fromLua);
        sort($fromGo);

        $this->assertSame($expected, $fromLua);
        $this->assertSame($expected, $fromGo);
    }

    public static function deniedOrigins(): iterable
    {
        $cases = [
            'expanded mapped loopback' => ['0:0:0:0:0:ffff:7f00:1', []],
            'expanded mapped dotted loopback' => ['0:0:0:0:0:ffff:127.0.0.1', []],
            'expanded mapped metadata' => ['0:0:0:0:0:ffff:a9fe:a9fe', []],
            '6to4 embedding a public address' => ['2002:808:808::1', []],
            'Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', []],
            'IPv4-compatible' => ['::808:808', []],
            'expanded mapped public address' => ['0:0:0:0:0:ffff:808:808', []],
            'private IPv4 explicit deny' => ['10.20.1.2', ['private_origin_allowlist' => ['10.0.0.0/8'], 'blocked_origin_networks' => ['10.20.0.0/16']]],
            'private IPv6 explicit deny' => ['fd00:20::1', ['private_origin_allowlist' => ['fc00::/7'], 'blocked_origin_networks' => ['fd00:20::/32']]],
            'expanded denied IPv6' => ['2606:4700:4700:0:0:0:0:1111', ['blocked_origin_addresses' => ['2606:4700:4700::1111']]],
            'compressed denied IPv6' => ['2606:4700:4700::1111', ['blocked_origin_addresses' => ['2606:4700:4700:0:0:0:0:1111']]],
        ];
        foreach ($cases as $name => [$address, $settings]) {
            foreach (['primary', 'backup'] as $role) {
                yield $name.' '.$role => [$address, $settings, $role];
            }
        }
    }

    private function origin(string $host): array
    {
        return ['host' => $host, 'scheme' => 'http', 'port' => 80, 'host_header' => 'origin.example', 'sni' => null,
            'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 1000, 'retry_count' => 0];
    }
}
