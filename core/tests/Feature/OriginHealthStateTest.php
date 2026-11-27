<?php

namespace Tests\Feature;

use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\User;
use App\Support\OriginHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OriginHealthStateTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{0: bool, 1: list<array{0: string, 1: string, 2: int}>, 3?: string}> */
    public static function transitions(): iterable
    {
        // monitoring, signals as [kind, status, age in seconds], expected record state
        yield 'no monitoring and no evidence' => [false, [], 'disabled'];
        yield 'monitoring waiting for its first check' => [true, [], 'pending'];
        yield 'active healthy' => [true, [['active', 'healthy', 30]], 'healthy'];
        yield 'active unhealthy' => [true, [['active', 'unhealthy', 30]], 'unhealthy'];
        yield 'active healthy with a passive failure' => [true, [['active', 'healthy', 30], ['passive', 'unhealthy', 30]], 'degraded'];
        yield 'monitoring off with an unhealthy passive report' => [false, [['passive', 'unhealthy', 30]], 'unhealthy'];
        yield 'monitoring off with a healthy manual test' => [false, [['manual', 'healthy', 600]], 'healthy'];
        yield 'passive failure expired' => [false, [['passive', 'unhealthy', OriginHealth::PASSIVE_TTL_SECONDS + 60]], 'stale'];
        yield 'manual test expired' => [false, [['manual', 'healthy', OriginHealth::MANUAL_TTL_SECONDS + 60]], 'stale'];
        yield 'active result older than three intervals' => [true, [['active', 'healthy', 3 * 300 + 60]], 'stale'];
        yield 'a passive failure does not hide a stale active result as healthy' => [true, [['active', 'healthy', 3 * 300 + 60], ['passive', 'unhealthy', 30]], 'unhealthy'];
    }

    /** @param list<array{0: string, 1: string, 2: int}> $signals */
    #[DataProvider('transitions')]
    public function test_every_state_transition(bool $monitoring, array $signals, string $expected): void
    {
        $record = $this->record(['health_check' => $monitoring ? ['enabled' => true, 'path' => '/', 'interval_seconds' => 300] : null]);
        $record->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
        foreach ($signals as [$kind, $status, $age]) {
            OriginHealth::record($record, 'primary', $kind, ['status' => $status, 'at' => now()->subSeconds($age)->toIso8601String()]);
        }

        $this->assertSame($expected, OriginHealth::summarize($record->refresh())['state']);
    }

    public function test_pending_becomes_unknown_when_a_monitored_origin_never_reports(): void
    {
        $record = $this->record(['health_check' => ['enabled' => true, 'path' => '/', 'interval_seconds' => 300]]);
        $record->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        $this->assertSame('unknown', OriginHealth::summarize($record->refresh())['state']);
    }

    public function test_signals_of_different_kinds_never_overwrite_each_other(): void
    {
        $record = $this->record(['health_check' => ['enabled' => true, 'path' => '/', 'interval_seconds' => 300]]);
        OriginHealth::record($record, 'primary', 'active', ['status' => 'healthy', 'observer' => 'edge-a', 'latency_ms' => 12]);
        OriginHealth::record($record->refresh(), 'primary', 'passive', ['status' => 'unhealthy', 'observer' => 'edge-b', 'failure_count' => 4]);
        OriginHealth::record($record->refresh(), 'primary', 'manual', ['status' => 'healthy', 'observer' => 'edge-a']);

        $signals = $record->refresh()->origin_signals['primary'];
        $this->assertSame('healthy', $signals['active']['status']);
        $this->assertSame(12, $signals['active']['latency_ms']);
        $this->assertSame('unhealthy', $signals['passive']['status']);
        $this->assertSame('healthy', $signals['manual']['status']);
        $this->assertSame('degraded', OriginHealth::summarize($record)['state']);
    }

    public function test_only_typed_fields_are_stored(): void
    {
        $record = $this->record();
        OriginHealth::record($record, 'primary', 'manual', ['status' => 'healthy', 'secret' => 'x', 'tls_result' => ['nested' => 1], 'latency_ms' => 5]);
        OriginHealth::record($record->refresh(), 'primary', 'passive', ['status' => 'unknown']);

        $stored = $record->refresh()->origin_signals['primary'];
        $this->assertSame(['status', 'latency_ms', 'checksum', 'at'], array_keys($stored['manual']));
        $this->assertArrayNotHasKey('passive', $stored);
    }

    public function test_editing_the_origin_makes_old_evidence_stale_and_keeps_it_for_the_unchanged_role(): void
    {
        $record = $this->record($this->backup());
        OriginHealth::record($record, 'primary', 'manual', ['status' => 'healthy']);
        OriginHealth::record($record->refresh(), 'backup', 'manual', ['status' => 'healthy']);
        $this->assertSame('healthy', OriginHealth::summarize($record->refresh())['roles']['backup']['state']);

        $origin = $record->origin;
        $origin['host'] = '1.1.1.1';
        $record->update(['origin' => $origin]);

        $summary = OriginHealth::summarize($record->refresh());
        $this->assertSame('stale', $summary['state']);
        $this->assertSame('healthy', $summary['roles']['backup']['state']);
    }

    public function test_removing_the_backup_drops_its_signals_and_a_mode_change_drops_everything(): void
    {
        $record = $this->record($this->backup());
        OriginHealth::record($record, 'backup', 'manual', ['status' => 'unhealthy']);
        $origin = $record->refresh()->origin;
        $origin['backup'] = null;
        $origin['failover'] = null;
        $record->update(['origin' => $origin]);
        $this->assertNull($record->refresh()->origin_signals);

        OriginHealth::record($record, 'primary', 'manual', ['status' => 'healthy']);
        $record->update(['mode' => 'dns_only', 'origin' => null]);
        $this->assertNull($record->refresh()->origin_signals);
    }

    public function test_turning_monitoring_off_clears_the_active_state_but_keeps_passive_detection_and_failover(): void
    {
        $record = $this->record($this->backup(['health_check' => ['enabled' => true, 'path' => '/', 'interval_seconds' => 300]]));
        OriginHealth::record($record, 'primary', 'active', ['status' => 'healthy']);
        OriginHealth::record($record->refresh(), 'primary', 'passive', ['status' => 'unhealthy', 'failure_count' => 3]);
        $failoverBefore = [$record->origin['backup'], $record->origin['failover']];

        $origin = $record->origin;
        $origin['health_check'] = null;
        $record->update(['origin' => $origin]);
        $record->refresh();

        $this->assertArrayNotHasKey('active', $record->origin_signals['primary']);
        $this->assertSame('disabled', OriginHealth::summarize($record)['roles']['primary']['signals']['active']['state']);
        $this->assertSame('disabled', OriginHealth::summarize($record)['roles']['primary']['monitoring']);
        // Editing the origin changes its checksum, so the earlier passive report is stale; a new one is still accepted.
        OriginHealth::record($record, 'primary', 'passive', ['status' => 'unhealthy', 'failure_count' => 5]);
        $this->assertSame('unhealthy', OriginHealth::summarize($record->refresh())['state']);
        $this->assertSame($failoverBefore, [$record->origin['backup'], $record->origin['failover']]);
    }

    public function test_a_record_without_signals_reads_the_legacy_value_until_it_is_written_again(): void
    {
        $record = $this->record();
        $this->assertSame('disabled', OriginHealth::summarize($record)['state']);

        $record->forceFill(['origin_health' => ['status' => 'unhealthy', 'source' => 'passive', 'reported_at' => now()->toIso8601String()]])->saveQuietly();
        $this->assertSame('unhealthy', OriginHealth::summarize($record->refresh())['state']);

        $record->forceFill(['origin_health' => ['status' => 'healthy', 'reported_at' => now()->subDays(3)->toIso8601String()]])->saveQuietly();
        $this->assertSame('stale', OriginHealth::summarize($record->refresh())['state']);
    }

    public function test_the_api_resource_and_the_health_endpoint_agree(): void
    {
        $record = $this->record();
        OriginHealth::record($record, 'primary', 'manual', ['status' => 'healthy', 'latency_ms' => 20]);
        $owner = User::factory()->create();
        $record->domain->users()->attach($owner);

        $health = $this->actingAs($owner)->getJson("/api/domains/{$record->domain_id}/dns/records/{$record->id}/origin/health")->assertOk()->json('data');
        $listed = $this->actingAs($owner)->getJson("/api/domains/{$record->domain_id}/dns/records/{$record->id}")->assertOk()->json('data.origin_health');

        $this->assertSame('healthy', $health['state']);
        $this->assertSame($health, $listed);
        $this->assertSame('healthy', $health['status']);
        $this->assertSame(20, $health['roles']['primary']['signals']['manual']['latency_ms']);
    }

    /** @param array<string, mixed> $override */
    private function record(array $override = []): DnsRecord
    {
        $domain = Domain::query()->create(['name' => 'health.example', 'display_name' => 'Health', 'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
        $origin = array_merge(['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false,
            'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => null, 'backup' => null, 'failover' => null], $override);

        return $domain->dnsRecords()->create(['type' => 'A', 'mode' => 'proxied', 'name' => 'www.health.example', 'content' => '8.8.8.8',
            'content_hash' => hash('sha256', 'x'), 'ttl' => 60, 'origin' => $origin]);
    }

    /** @return array<string, mixed> */
    private function backup(array $extra = []): array
    {
        return $extra + ['backup' => ['host' => '8.8.4.4', 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false,
            'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => null],
            'failover' => ['failure_threshold' => 2, 'recovery_threshold' => 2, 'hold_down_seconds' => 10, 'failback_delay_seconds' => 10]];
    }
}
