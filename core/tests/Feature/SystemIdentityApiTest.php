<?php

namespace Tests\Feature;

use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_validates_and_applies_typed_dns_identity_through_an_operation(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $payload = $this->validPayload();

        $this->actingAs($admin)->postJson('/api/admin/system/settings/dns/validate', $payload)
            ->assertOk()->assertJsonPath('data.valid', true);

        $response = $this->actingAs($admin)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/dns', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $operationId = $response->json('data.id');
        Queue::assertPushed(
            ApplyPlatformDnsSettings::class,
            fn (ApplyPlatformDnsSettings $job): bool => $job->operationId === $operationId,
        );
        (new ApplyPlatformDnsSettings($operationId))->handle();
        $this->assertDatabaseHas('operations', ['id' => $operationId, 'type' => 'platform_dns_identity.update', 'status' => 'succeeded']);
        $this->assertDatabaseHas('platform_dns_settings', ['id' => 1, 'platform_domain' => 'cdnf.test']);
        $this->actingAs($admin)->getJson("/api/operations/$operationId")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_dns_settings.update_requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_dns_settings.applied']);
    }

    public function test_dns_identity_requires_both_ipv4_and_ipv6_glue(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->validPayload();
        $payload['nameservers'][0]['ipv6'] = 'not-ipv6';

        $this->actingAs($admin)->postJson('/api/admin/system/settings/dns/validate', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('nameservers.0.ipv6');
    }

    public function test_domain_user_cannot_read_dns_identity_or_other_users_operation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $operation = Operation::query()->create(['actor_id' => $owner->id, 'type' => 'test', 'status' => 'pending', 'input' => []]);

        $this->actingAs($other)->getJson('/api/admin/system/settings/dns')->assertForbidden();
        $this->actingAs($other)->getJson("/api/operations/$operation->id")->assertForbidden();
    }

    private function validPayload(): array
    {
        return [
            'platform_domain' => 'cdnf.test',
            'proxy_hostname' => 'proxy.cdnf.test',
            'nameservers' => [
                ['hostname' => 'ns1.cdnf.test', 'ipv4' => '192.0.2.10', 'ipv6' => '2001:db8::10'],
                ['hostname' => 'ns2.cdnf.test', 'ipv4' => '192.0.2.11', 'ipv6' => '2001:db8::11'],
            ],
            'soa_primary' => 'ns1.cdnf.test',
            'soa_mailbox' => 'hostmaster.cdnf.test',
            'soa_refresh' => 3600,
            'soa_retry' => 600,
            'soa_expire' => 1209600,
            'soa_minimum_ttl' => 300,
            'default_ttl' => 300,
            'cluster_targets' => ['pdns-auth:8081'],
        ];
    }
}
