<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\Domain;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\PlatformSettings;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_settings_expose_current_values_defaults_and_descriptions_to_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->getJson('/api/admin/system/settings')->assertOk();

        $this->assertCount(10, $response->json('data'));
        $settings = collect($response->json('data'));
        $dnsLifecycle = $settings->firstWhere('group', 'dns_lifecycle');
        $this->assertSame(7, $dnsLifecycle['fields'][0]['value']);
        $this->assertSame(7, $dnsLifecycle['fields'][0]['default']);
        $this->assertNotEmpty($dnsLifecycle['fields'][0]['description']);
        $this->assertNotNull($settings->firstWhere('group', 'telemetry'));
        $this->assertNotNull($settings->firstWhere('group', 'operations'));
        $this->assertNull($settings->firstWhere('group', 'observability')['fields'][0]['value']);
        $this->assertDatabaseCount('system_settings', 10);
    }

    public function test_shared_timezone_is_validated_audited_and_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $key = (string) Str::uuid();
        $payload = ['values' => ['timezone' => 'Asia/Tehran']];
        $this->actingAs($admin)->withHeader('Idempotency-Key', $key)
            ->patchJson('/api/admin/system/settings/display', $payload)->assertOk()
            ->assertJsonPath('data.setting.revision', 2)->assertJsonPath('data.operation', null);
        $this->withHeader('Idempotency-Key', $key)->patchJson('/api/admin/system/settings/display', $payload)->assertOk();
        $this->assertSame(2, SystemSetting::query()->findOrFail('display')->revision);
        $this->assertSame('Asia/Tehran', FilamentTimezone::get());
        $this->assertDatabaseHas('audit_logs', ['action' => 'system_settings.updated', 'subject_id' => 'display']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/display', ['values' => ['timezone' => 'Invalid/Zone']])
            ->assertUnprocessable()->assertJsonValidationErrors(['timezone']);
        $this->assertSame('Asia/Tehran', SystemSetting::query()->findOrFail('display')->values['timezone']);
        $this->actingAs(User::factory()->create())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/display', $payload)->assertForbidden();
    }

    public function test_platform_form_and_filament_components_share_the_saved_timezone(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(\App\Filament\Admin\Pages\PlatformSettings::class)
            ->fillForm(['display.timezone' => 'Asia/Tehran'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Asia/Tehran', FilamentTimezone::get());
        $column = TextColumn::make('created_at')->dateTime('Y-m-d H:i P');
        $entry = TextEntry::make('created_at')->container(Schema::make())->dateTime('Y-m-d H:i P');
        $this->assertSame('2026-09-20 15:30 +03:30', $column->formatState('2026-09-20 12:00:00'));
        $this->assertSame('2026-09-20 15:30 +03:30', $entry->formatState('2026-09-20 12:00:00'));
        $this->assertSame('Asia/Tehran', DateTimePicker::make('from')->getTimezone());
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_display_migration_preserves_existing_operator_preference(): void
    {
        app(PlatformSettings::class)->update('display', ['timezone' => 'Asia/Tehran']);
        $migration = require database_path('migrations/2026_09_20_170000_add_display_timezone_setting.php');
        $migration->up();
        $migration->down();
        $this->assertSame('Asia/Tehran', SystemSetting::query()->findOrFail('display')->values['timezone']);
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

    public function test_optional_grafana_url_is_validated_audited_and_has_no_runtime_operation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/observability', ['values' => ['grafana_explore_url' => 'https://grafana.example.test/explore']])
            ->assertOk()
            ->assertJsonPath('data.setting.fields.0.value', 'https://grafana.example.test/explore')
            ->assertJsonPath('data.operation', null);

        $this->assertDatabaseHas('audit_logs', ['action' => 'system_settings.updated', 'subject_id' => 'observability']);
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson('/api/admin/system/settings/observability', ['values' => ['grafana_explore_url' => 'ftp://grafana.example.test']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grafana_explore_url']);
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
        $this->artisan('cdnf:platform:settings:show', ['group' => 'dns_lifecycle', '--json' => true])
            ->expectsOutputToContain('deprovision_delay_days')->assertSuccessful();
        $this->artisan('cdnf:platform:settings:set', ['group' => 'dns_lifecycle', 'values' => '{"domain_reclaim_cooldown_days":21}'])
            ->expectsOutputToContain('revision 2')->assertSuccessful();
        $this->assertSame(21, SystemSetting::query()->findOrFail('dns_lifecycle')->values['domain_reclaim_cooldown_days']);
    }
}
