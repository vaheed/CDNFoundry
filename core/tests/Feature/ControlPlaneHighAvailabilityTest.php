<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\AdminDashboard;
use App\Models\ControlNode;
use App\Models\ControlPlaneState;
use App\Models\DnsCluster;
use App\Models\Edge;
use App\Support\ArtifactSigner;
use App\Support\ControlPlane;
use App\Support\EdgeCertificateAuthority;
use App\Support\SystemHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ControlPlaneHighAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function identity(): array
    {
        $edge = Edge::query()->create(['name' => 'ha-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'management_ipv4' => '203.0.113.81', 'enabled' => true, 'registered_at' => now()]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $edge->id], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrPem);
        $signed = EdgeCertificateAuthority::sign($csrPem, $edge->id);
        $edge->update(['identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at']]);

        return [$edge, ['X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $signed['serial'], 'X-Edge-Certificate-Pem' => rawurlencode($signed['certificate']), 'X-Edge-Proxy-Secret' => config('edge.proxy_secret')]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        ControlPlane::forgetFence();
    }

    public function test_every_edge_control_response_carries_the_epoch_and_active_state(): void
    {
        ControlPlaneState::current()->update(['epoch' => 7]);
        [, $headers] = $this->identity();

        $this->withHeaders($headers)->getJson('/edge/v1/tasks')->assertOk()
            ->assertHeader('X-CDNF-Control-Epoch', '7')->assertHeader('X-CDNF-Control-State', 'active');
        $this->flushHeaders()->getJson('/edge/v1/tasks')->assertUnauthorized()->assertHeader('X-CDNF-Control-Epoch', '7');
    }

    public function test_a_node_that_is_not_the_recorded_active_node_answers_standby(): void
    {
        ControlPlaneState::current()->update(['active_node' => 'control-b']);
        config(['edge.control_node_name' => 'control-a']);
        [, $headers] = $this->identity();

        $this->withHeaders($headers)->getJson('/edge/v1/tasks')->assertStatus(503)
            ->assertHeader('X-CDNF-Control-State', 'standby')->assertJsonPath('code', 'control_standby');
    }

    public function test_the_published_state_is_secret_free_and_follows_fencing(): void
    {
        ControlPlaneState::current()->update(['epoch' => 3]);
        $response = $this->getJson('/control-state')->assertOk()->assertExactJson(['epoch' => 3, 'role' => 'active']);
        // A poll must not open a session or set a cookie.
        $this->assertSame([], $response->headers->getCookies());

        ControlPlaneState::current()->update(['active_node' => 'control-b']);
        config(['edge.control_node_name' => 'control-a']);
        $this->getJson('/control-state')->assertOk()->assertJsonPath('role', 'standby');
    }

    public function test_a_higher_peer_epoch_fences_this_node(): void
    {
        ControlPlaneState::current()->update(['epoch' => 2]);
        config(['app.url' => 'https://control.test', 'edge.control_peer_addresses' => ['203.0.113.50']]);
        Http::fake(['control.test/*' => Http::response(['epoch' => 3, 'role' => 'active'])]);

        $this->assertSame('superseded_by_higher_epoch', ControlPlane::fencedReason(consultPeers: true));
        $this->artisan('cdnf:control:guard')->assertFailed();

        config(['app.url' => 'https://same.test']);
        Http::fake(['same.test/*' => Http::response(['epoch' => 2, 'role' => 'standby'])]);
        $this->artisan('cdnf:control:guard')->assertSuccessful();

        config(['app.url' => 'https://down.test']);
        Http::fake(['down.test/*' => Http::response('', 500)]);
        $this->artisan('cdnf:control:guard')->assertSuccessful();
    }

    public function test_the_scheduler_runs_nothing_on_a_fenced_node(): void
    {
        ControlPlaneState::current()->update(['active_node' => 'control-b']);
        config(['edge.control_node_name' => 'control-a']);
        ControlPlane::forgetFence();

        $events = app(Schedule::class)->events();
        $gated = collect($events)->reject(fn ($event): bool => str_contains((string) ($event->command ?? ''), 'cdnf:control:enforce'));
        $this->assertGreaterThan(10, $gated->count());
        foreach ($gated as $event) {
            $this->assertFalse($event->filtersPass($this->app), (string) ($event->command ?? $event->description));
        }
    }

    public function test_the_address_list_is_signed_and_unchanged_for_a_matching_checksum(): void
    {
        $this->artisan('cdnf:control:manage', ['action' => 'addresses', '--addresses' => '203.0.113.10,2001:db8::10'])->assertSuccessful();
        [, $headers] = $this->identity();

        $response = $this->withHeaders($headers)->getJson('/edge/v1/control-addresses')->assertOk();
        $encoded = base64_decode($response->json('encoded_payload'));
        $this->assertSame(['addresses' => ['203.0.113.10', '2001:db8::10']], json_decode($encoded, true));
        $this->assertSame(hash('sha256', $encoded), $response->json('checksum'));
        $this->assertTrue(sodium_crypto_sign_verify_detached(sodium_hex2bin($response->json('signature')), $response->json('checksum'), sodium_hex2bin(ArtifactSigner::publicKey())));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            sodium_hex2bin($response->json('delivery_signature')),
            'control-addresses|'.$response->json('checksum').'|0|'.$response->json('expires_at'),
            sodium_hex2bin(ArtifactSigner::publicKey()),
        ));
        $this->withHeaders($headers)->getJson('/edge/v1/control-addresses?checksum='.$response->json('checksum'))->assertOk()->assertJson(['unchanged' => true]);
    }

    public function test_more_than_two_or_invalid_addresses_are_refused(): void
    {
        $this->artisan('cdnf:control:manage', ['action' => 'addresses', '--addresses' => '203.0.113.1,203.0.113.2,203.0.113.3'])->assertFailed();
        $this->artisan('cdnf:control:manage', ['action' => 'addresses', '--addresses' => 'not-an-ip'])->assertFailed();
        $this->assertSame([], ControlPlane::addresses());
    }

    public function test_ready_needs_lag_fingerprints_and_every_edge_holding_the_addresses(): void
    {
        $this->assertSame('none', ControlPlane::status()['ha']);
        [$edge] = $this->identity();
        $this->artisan('cdnf:control:manage', ['action' => 'addresses', '--addresses' => '203.0.113.10,203.0.113.11'])->assertSuccessful();
        $fingerprints = json_encode(['app_key' => 'aaaa', 'artifact_signing_key' => 'bbbb']);
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-a', '--role' => 'active', '--address' => '203.0.113.10', '--fingerprints' => $fingerprints])->assertSuccessful();
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-b', '--role' => 'standby', '--address' => '203.0.113.11', '--fingerprints' => $fingerprints, '--lag' => 2, '--valkey-linked' => 1])->assertSuccessful();

        $status = ControlPlane::status();
        $this->assertSame('degraded', $status['ha']);
        $this->assertSame(['edges_missing_control_addresses'], $status['reasons']);

        $edge->update(['capacity' => ['control_addresses_checksum' => ControlPlane::addressChecksum()]]);
        $this->assertSame('ready', ControlPlane::status()['ha']);

        ControlNode::query()->where('name', 'control-b')->update(['valkey_linked' => false]);
        $this->assertContains('valkey_replication_broken', ControlPlane::status()['reasons']);
        ControlNode::query()->where('name', 'control-b')->update(['valkey_linked' => true, 'replication_lag_seconds' => 11]);
        $this->assertContains('replication_lag', ControlPlane::status()['reasons']);
        ControlNode::query()->where('name', 'control-b')->update(['replication_lag_seconds' => 1, 'fingerprints' => json_encode(['app_key' => 'aaaa', 'artifact_signing_key' => 'cccc'])]);
        $status = ControlPlane::status();
        $this->assertSame('degraded', $status['ha']);
        $this->assertContains('secret_fingerprint_mismatch', $status['reasons']);
    }

    public function test_a_second_node_cannot_take_an_occupied_role(): void
    {
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-a', '--role' => 'standby', '--address' => '203.0.113.10'])->assertSuccessful();
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-b', '--role' => 'standby', '--address' => '203.0.113.11'])->assertSuccessful();

        $this->assertSame(['control-b'], ControlNode::query()->where('role', 'standby')->pluck('name')->all());
    }

    public function test_promotion_increments_the_epoch_and_drops_the_old_active_node(): void
    {
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-a', '--role' => 'active', '--address' => '203.0.113.10'])->assertSuccessful();
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-b', '--role' => 'standby', '--address' => '203.0.113.11'])->assertSuccessful();

        $this->artisan('cdnf:control:manage', ['action' => 'promote', '--name' => 'control-b'])->expectsOutput('2')->assertSuccessful();

        $state = ControlPlaneState::current();
        $this->assertSame(2, $state->epoch);
        $this->assertSame('control-b', $state->active_node);
        $this->assertSame(['control-b' => 'active'], ControlNode::query()->pluck('role', 'name')->all());

        // Running it again after an interruption does not count a second promotion.
        $this->artisan('cdnf:control:manage', ['action' => 'promote', '--name' => 'control-b'])->expectsOutput('2')->assertSuccessful();
        $this->assertSame(2, ControlPlaneState::current()->epoch);
    }

    public function test_removing_the_standby_returns_to_a_single_address_and_no_ha(): void
    {
        $this->artisan('cdnf:control:manage', ['action' => 'addresses', '--addresses' => '203.0.113.10,203.0.113.11'])->assertSuccessful();
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-a', '--role' => 'active', '--address' => '203.0.113.10'])->assertSuccessful();
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-b', '--role' => 'standby', '--address' => '203.0.113.11'])->assertSuccessful();

        $this->artisan('cdnf:control:manage', ['action' => 'remove-standby'])->assertSuccessful();

        $this->assertSame(['203.0.113.10'], ControlPlane::addresses());
        $this->assertSame('none', ControlPlane::status()['ha']);
    }

    public function test_fingerprints_never_contain_a_secret_value(): void
    {
        Artisan::call('cdnf:control:fingerprints');
        $output = Artisan::output();
        $this->assertStringNotContainsString((string) config('app.key'), $output);
        $this->assertStringNotContainsString((string) config('edge.proxy_secret'), $output);
        $decoded = json_decode($output, true);
        $this->assertArrayHasKey('app_key', $decoded);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $decoded['app_key']);
    }

    public function test_dns_nodes_are_told_to_follow_both_control_nodes_as_primaries(): void
    {
        DnsCluster::query()->create([
            'name' => 'primary', 'location' => 'control', 'enabled' => true, 'api_url' => 'http://pdns.test', 'api_key' => 'private-test-key',
            'transfer_endpoint' => '203.0.113.10:5300', 'nameservers' => [['hostname' => 'a.test'], ['hostname' => 'b.test']],
        ]);
        [, $headers] = $this->identity();

        $this->withHeaders($headers)->getJson('/edge/v1/dns/transfer')->assertOk()->assertJsonPath('data.primaries', ['203.0.113.10:5300']);

        $this->artisan('cdnf:control:manage', ['action' => 'addresses', '--addresses' => '203.0.113.10,2001:db8::11'])->assertSuccessful();
        $this->withHeaders($headers)->getJson('/edge/v1/dns/transfer')->assertOk()
            ->assertJsonPath('data.primaries', ['203.0.113.10:5300', '[2001:db8::11]:5300']);
    }

    public function test_the_heartbeat_records_which_address_list_the_edge_holds(): void
    {
        [$edge, $headers] = $this->identity();
        $edge->cells()->create(['slot' => 1, 'name' => 'cell-01', 'status' => 'ready']);
        $checksum = str_repeat('a', 64);

        $this->withHeaders($headers)->postJson('/edge/v1/heartbeat', [
            'agent_version' => '1.0.0', 'listener_ready' => true, 'active_sequence' => 0, 'cells' => [['name' => 'cell-01', 'status' => 'ready', 'capacity' => ['active_connections' => 0]]], 'control_addresses_checksum' => $checksum,
        ])->assertOk();

        $this->assertSame($checksum, $edge->fresh()->capacity['control_addresses_checksum']);
    }

    public function test_recording_the_active_node_makes_it_the_only_node_that_may_serve(): void
    {
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-a', '--role' => 'active', '--address' => '203.0.113.10'])->assertSuccessful();
        $this->assertSame('control-a', ControlPlaneState::current()->active_node);

        config(['edge.control_node_name' => 'control-b']);
        ControlPlane::forgetFence();
        $this->assertSame('not_active_node', ControlPlane::fencedReason());
        config(['edge.control_node_name' => 'control-a']);
        $this->assertNull(ControlPlane::fencedReason());
    }

    public function test_a_single_node_shows_ha_none_as_a_healthy_warning_on_the_dashboard(): void
    {
        $component = app(SystemHealth::class)->components()['control_ha'];
        $this->assertSame('healthy', $component['status']);
        $this->assertSame('none', $component['details']['ha']);
        $this->assertTrue($component['details']['warning']);

        $row = collect((new AdminDashboard)->getComponentStateProperty())->firstWhere('key', 'control_ha');
        $this->assertSame('Control Ha', $row['name']);
        $this->assertStringContainsString('Ha: none', $row['summary']);
        $this->assertStringContainsString('Warning: yes', $row['summary']);
    }

    public function test_a_degraded_pair_lists_its_reasons_on_the_dashboard(): void
    {
        $this->artisan('cdnf:control:manage', ['action' => 'addresses', '--addresses' => '203.0.113.10,203.0.113.11'])->assertSuccessful();
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-a', '--role' => 'active', '--address' => '203.0.113.10', '--fingerprints' => '{"a":"1"}'])->assertSuccessful();
        $this->artisan('cdnf:control:manage', ['action' => 'record', '--name' => 'control-b', '--role' => 'standby', '--address' => '203.0.113.11', '--fingerprints' => '{"a":"2"}', '--lag' => 30])->assertSuccessful();

        $row = collect((new AdminDashboard)->getComponentStateProperty())->firstWhere('key', 'control_ha');
        $this->assertSame('degraded', $row['status']);
        $this->assertStringContainsString('secret_fingerprint_mismatch', $row['summary']);
        $this->assertStringContainsString('replication_lag', $row['summary']);
    }
}
