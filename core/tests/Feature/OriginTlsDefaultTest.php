<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Support\OriginData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OriginTlsDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_omitted_value_is_false_on_create_and_an_explicit_value_is_kept(): void
    {
        $omitted = $this->origin();
        unset($omitted['verify_tls']);

        $this->assertFalse(OriginData::validate($omitted)['verify_tls']);
        $this->assertTrue(OriginData::validate($this->origin(['verify_tls' => true]))['verify_tls']);
        $this->assertFalse(OriginData::validate($this->origin(['verify_tls' => false]))['verify_tls']);
    }

    public function test_an_omitted_value_on_update_never_changes_the_stored_one_for_primary_and_backup(): void
    {
        $stored = $this->origin(['verify_tls' => true]) + ['backup' => $this->origin(['host' => '1.1.1.1', 'verify_tls' => true]), 'failover' => $this->failover()];
        $input = $stored;
        unset($input['verify_tls'], $input['backup']['verify_tls']);

        $updated = OriginData::validate($input, $stored);

        $this->assertTrue($updated['verify_tls']);
        $this->assertTrue($updated['backup']['verify_tls']);
        $this->assertFalse(OriginData::validate($input)['verify_tls']);
    }

    public function test_enabling_verification_still_requires_a_server_name_and_a_malformed_value_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        OriginData::validate($this->origin(['verify_tls' => true, 'sni' => null]));
    }

    public function test_the_origin_endpoint_creates_with_false_and_an_update_without_the_field_keeps_the_stored_value(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'tlsdefault.example', 'display_name' => 'TLS default',
            'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
        $domain->users()->attach($owner);
        $record = $domain->dnsRecords()->create(['type' => 'A', 'mode' => 'proxied', 'name' => 'www.tlsdefault.example',
            'content' => '8.8.8.8', 'content_hash' => hash('sha256', '8.8.8.8'), 'ttl' => 60, 'origin' => $this->origin(['verify_tls' => true])]);

        $body = $this->origin(['host' => '8.8.4.4']);
        unset($body['verify_tls']);
        $this->actingAs($owner)->putJson("/api/domains/{$domain->id}/dns/records/{$record->id}/origin", $body)->assertAccepted();
        $this->assertTrue($record->refresh()->origin['verify_tls']);

        $created = $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/dns/records", [
            'type' => 'A', 'name' => 'api', 'ttl' => 60, 'mode' => 'proxied', 'origin' => array_diff_key($this->origin(['verify_tls' => false]), ['verify_tls' => 0]),
        ])->assertCreated();
        $this->assertFalse($domain->dnsRecords()->findOrFail($created->json('data.id'))->origin['verify_tls']);
    }

    /** @return array<string, mixed> */
    private function origin(array $override = []): array
    {
        return array_merge(['host' => '8.8.8.8', 'scheme' => 'https', 'port' => 443, 'host_header' => 'origin.example', 'sni' => 'origin.example',
            'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 1000, 'retry_count' => 0], $override);
    }

    /** @return array<string, int> */
    private function failover(): array
    {
        return ['failure_threshold' => 2, 'recovery_threshold' => 2, 'hold_down_seconds' => 10, 'failback_delay_seconds' => 10];
    }
}
