<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OriginFailoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_atomically_configure_one_validated_backup_and_replay_the_request(): void
    {
        Queue::fake();
        [$owner, $domain, $record] = $this->proxiedRecord();
        $payload = $this->origin('8.8.8.8') + [
            'backup' => $this->origin('1.1.1.1'),
            'failover' => [
                'failure_threshold' => 3, 'recovery_threshold' => 2,
                'hold_down_seconds' => 30, 'failback_delay_seconds' => 60,
            ],
        ];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        $first = $this->actingAs($owner)->withHeaders($headers)
            ->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $payload)
            ->assertAccepted();
        $this->actingAs($owner)->withHeaders($headers)
            ->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $payload)
            ->assertAccepted()->assertJsonPath('data.operation_id', $first->json('data.operation_id'));
        $this->actingAs($owner)->withHeaders($headers)
            ->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", [...$payload, 'failover' => [...$payload['failover'], 'failure_threshold' => 4]])
            ->assertConflict();

        $saved = $record->refresh()->origin;
        $this->assertSame('1.1.1.1', $saved['backup']['host']);
        $this->assertSame(3, $saved['failover']['failure_threshold']);
        $this->assertSame(2, $domain->refresh()->revision);
    }

    public function test_backup_reuses_destination_safety_and_bounded_policy_validation(): void
    {
        [$owner, $domain, $record] = $this->proxiedRecord();
        $payload = $this->origin('8.8.8.8') + [
            'backup' => $this->origin('127.0.0.1'),
            'failover' => [
                'failure_threshold' => 0, 'recovery_threshold' => 21,
                'hold_down_seconds' => 4, 'failback_delay_seconds' => 86401,
            ],
        ];

        $this->actingAs($owner)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'backup.host', 'failover.failure_threshold', 'failover.recovery_threshold',
                'failover.hold_down_seconds', 'failover.failback_delay_seconds',
            ]);

        $payload['backup'] = $this->origin('8.8.8.8');
        $payload['failover'] = [
            'failure_threshold' => 3, 'recovery_threshold' => 2,
            'hold_down_seconds' => 30, 'failback_delay_seconds' => 60,
        ];
        $this->actingAs($owner)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('backup.host');
        $this->assertNull($record->refresh()->origin['backup'] ?? null);
    }

    public function test_unassigned_user_cannot_view_or_mutate_failover_state(): void
    {
        [, $domain, $record] = $this->proxiedRecord();
        $other = User::factory()->create();

        $this->actingAs($other)->getJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin")->assertForbidden();
        $this->actingAs($other)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $this->origin('1.1.1.1'))->assertForbidden();
    }

    public function test_artifact_applies_the_same_safety_envelope_to_both_origins(): void
    {
        Queue::fake();
        [$owner, $domain, $record] = $this->proxiedRecord();
        $edge = Edge::query()->create(['name' => 'failover-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.80']);
        $origin = $this->origin('8.8.8.8') + [
            'backup' => $this->origin('2001:4860:4860::8888'),
            'failover' => [
                'failure_threshold' => 2, 'recovery_threshold' => 2,
                'hold_down_seconds' => 10, 'failback_delay_seconds' => 10,
            ],
        ];
        $record->update(['origin' => $origin]);
        $domain->update(['lifecycle_state' => 'active', 'revision' => 2]);

        (new ReconcileEdgeDomain($domain->id))->handle();

        $compiled = EdgeArtifact::query()->where('edge_id', $edge->id)->latest('sequence')->firstOrFail()->payload['hostnames'][0]['origin'];
        $this->assertSame($compiled['private_allowlist'], $compiled['backup']['private_allowlist']);
        $this->assertSame($compiled['blocked_networks'], $compiled['backup']['blocked_networks']);
        $this->assertSame($compiled['blocked_addresses'], $compiled['backup']['blocked_addresses']);
        $this->assertSame('2001:4860:4860::8888', $compiled['backup']['host']);
    }

    private function proxiedRecord(): array
    {
        $owner = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'failover-'.uniqid().'.example', 'display_name' => 'Failover', 'revision' => 1]);
        $domain->users()->attach($owner);
        $record = $domain->dnsRecords()->create([
            'type' => 'A', 'name' => 'www.'.$domain->name, 'content' => '8.8.8.8',
            'content_hash' => hash('sha256', '8.8.8.8'), 'ttl' => 60, 'mode' => 'proxied',
            'origin' => $this->origin('8.8.8.8'),
        ]);

        return [$owner, $domain, $record];
    }

    private function origin(string $host): array
    {
        return [
            'host' => $host, 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example',
            'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 500,
            'response_timeout_ms' => 1000, 'retry_count' => 0, 'websocket' => false,
            'health_check' => null,
        ];
    }
}
