<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\Domain;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_settings_expose_current_values_defaults_and_descriptions_to_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->getJson('/api/admin/system/settings')->assertOk();

        $this->assertCount(8, $response->json('data'));
        $settings = collect($response->json('data'));
        $dnsLifecycle = $settings->firstWhere('group', 'dns_lifecycle');
        $this->assertSame(7, $dnsLifecycle['fields'][0]['value']);
        $this->assertSame(7, $dnsLifecycle['fields'][0]['default']);
        $this->assertNotEmpty($dnsLifecycle['fields'][0]['description']);
        $this->assertNotNull($settings->firstWhere('group', 'telemetry'));
        $this->assertNotNull($settings->firstWhere('group', 'operations'));
        $this->assertDatabaseCount('system_settings', 8);
    }

    public function test_dns_lifecycle_update_is_typed_audited_and_reads_from_postgresql(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings', ['group' => 'dns_lifecycle', 'values' => ['deprovision_delay_days' => 14]])
            ->assertOk()->assertJsonPath('data.setting.revision', 2)
            ->assertJsonPath('data.setting.fields.0.value', 14)
            ->assertJsonPath('data.operation', null);

        $this->assertSame(14, SystemSetting::query()->findOrFail('dns_lifecycle')->values['deprovision_delay_days']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system_settings.updated', 'subject_id' => 'dns_lifecycle']);
    }

    public function test_runtime_setting_update_returns_operation_and_queues_bounded_reconciliation(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/edge_runtime', ['values' => ['heartbeat_fresh_seconds' => 60]])
            ->assertAccepted()->assertJsonPath('data.operation.type', 'system_settings.update')
            ->assertJsonPath('data.operation.status', 'pending');

        $operationId = $response->json('data.operation.id');
        Queue::assertPushed(ReconcilePlatformDnsIdentity::class);
        Queue::assertPushed(ReconcileAllEdgeDomains::class, fn (ReconcileAllEdgeDomains $job): bool => $job->operationId === $operationId);
    }

    public function test_domain_deprovisioning_uses_the_database_window_not_environment_configuration(): void
    {
        $user = User::factory()->create();
        $setting = SystemSetting::query()->findOrFail('dns_lifecycle');
        $setting->update(['values' => [...$setting->values, 'deprovision_delay_days' => 14]]);
        $domain = Domain::query()->create(['name' => 'database-policy.example', 'display_name' => 'database-policy.example', 'lifecycle_state' => DomainLifecycleState::Active]);
        $domain->users()->attach($user);
        $now = now()->startOfSecond();
        $this->travelTo($now);

        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->deleteJson("/api/domains/{$domain->id}")->assertAccepted();

        $this->assertSame($now->addDays(14)->toIso8601String(), $domain->refresh()->deprovision_after->toIso8601String());
        $this->travelBack();
    }

    public function test_invalid_unknown_and_unauthorized_settings_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/dns_lifecycle', ['values' => ['deprovision_delay_days' => 0]])
            ->assertUnprocessable()->assertJsonValidationErrors(['deprovision_delay_days']);
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/origin_safety', ['values' => ['private_origin_allowlist' => ['0.0.0.0/0']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['private_origin_allowlist.0']);
        $this->actingAs($admin)->getJson('/api/admin/system/settings/not_real')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/admin/system/settings')->assertForbidden();
    }

    public function test_cli_shows_and_updates_the_same_database_rows(): void
    {
        $this->artisan('platform:settings:show', ['group' => 'dns_lifecycle', '--json' => true])
            ->expectsOutputToContain('deprovision_delay_days')->assertSuccessful();
        $this->artisan('platform:settings:set', ['group' => 'dns_lifecycle', 'values' => '{"domain_reclaim_cooldown_days":21}'])
            ->expectsOutputToContain('revision 2')->assertSuccessful();
        $this->assertSame(21, SystemSetting::query()->findOrFail('dns_lifecycle')->values['domain_reclaim_cooldown_days']);
    }
}
