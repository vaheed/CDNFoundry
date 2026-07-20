<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgeRevision;
use App\Models\EdgeTask;
use App\Models\EmergencyMode;
use App\Models\User;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_are_authorized_bounded_revisioned_and_compiled(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/security")->assertForbidden();
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security")
            ->assertOk()->assertJsonPath('data.profile', 'standard')->assertJsonPath('data.platform_ceilings.requests_per_second', 100);

        $invalid = $this->settings('protected');
        $invalid['limits']['requests_per_second'] = 51;
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security", $invalid)
            ->assertUnprocessable()->assertJsonValidationErrors('limits.requests_per_second');
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security", $this->settings('protected'))
            ->assertAccepted();

        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertSame('protected', $domain->security_settings['profile']);
        Queue::assertPushed(ReconcileEdgeDomain::class, fn ($job): bool => $job->domainId === $domain->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.settings_updated', 'subject_id' => (string) $domain->id]);
    }

    public function test_recommended_profiles_are_fixed_and_the_single_manual_profile_is_bounded(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();

        foreach (['standard', 'protected', 'quarantine'] as $profile) {
            $settings = $this->settings($profile);
            $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security", $settings)->assertAccepted();
            $this->assertSame(config("security.profiles.$profile"), $domain->refresh()->security_settings['limits']);

            $changed = $settings;
            $changed['limits']['requests_per_second']--;
            $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security", $changed)
                ->assertUnprocessable()->assertJsonValidationErrors('limits.requests_per_second');
        }

        $manual = $this->settings(SecurityConfig::MANUAL_PROFILE);
        $manual['limits']['requests_per_second'] = 37;
        $manual['limits']['request_burst'] = 61;
        $manual['limits']['origin_recovery_timeout'] = 120;
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security", $manual)->assertAccepted();
        $this->assertSame('manual', $domain->refresh()->security_settings['profile']);
        $this->assertSame(37, $domain->security_settings['limits']['requests_per_second']);
        $this->assertSame(120, $domain->security_settings['limits']['origin_recovery_timeout']);

        $manual['limits']['requests_per_second'] = config('security.profiles.standard.requests_per_second') + 1;
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security", $manual)
            ->assertUnprocessable()->assertJsonValidationErrors('limits.requests_per_second');
    }

    public function test_manual_limits_compile_under_stricter_operational_state_ceilings(): void
    {
        [, $domain] = $this->ownedDomain();
        $manual = SecurityConfig::defaults(SecurityConfig::MANUAL_PROFILE);
        $manual['limits']['requests_per_second'] = 80;
        $manual['limits']['origin_max_connections'] = 100;
        $domain->update(['security_settings' => $manual, 'security_state' => 'restricted']);

        $compiled = SecurityConfig::compile($domain->refresh());

        $this->assertSame('manual', $compiled['profile']);
        $this->assertSame('protected', $compiled['effective_profile']);
        $this->assertSame(50, $compiled['limits']['requests_per_second']);
        $this->assertSame(64, $compiled['limits']['origin_max_connections']);
    }

    public function test_ipv4_ipv6_cidr_and_geo_rules_validate_and_order_deterministically(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $valid = [
            ['match_type' => 'ip', 'value' => '192.0.2.1', 'action' => 'block', 'priority' => 20],
            ['match_type' => 'ip', 'value' => '2001:db8::1', 'action' => 'allow', 'priority' => 10],
            ['match_type' => 'cidr', 'value' => '2001:db8::/32', 'action' => 'block', 'priority' => 10],
            ['match_type' => 'country', 'value' => 'ir', 'action' => 'block', 'priority' => 30],
            ['match_type' => 'continent', 'value' => 'AS', 'action' => 'allow', 'priority' => 40],
        ];
        foreach ($valid as $rule) {
            $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $rule)->assertCreated();
        }
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", [
            'match_type' => 'cidr', 'value' => '2001:db8::/129', 'action' => 'block', 'priority' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", [
            'match_type' => 'country', 'value' => 'XX', 'action' => 'block', 'priority' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        (new ReconcileEdgeDomain($domain->id))->handle();
        $snapshot = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail()->snapshot;
        $this->assertSame([10, 10, 20, 30, 40], collect($snapshot['security']['rules'])->pluck('priority')->all());
        $samePriority = collect($snapshot['security']['rules'])->where('priority', 10)->pluck('id')->all();
        $this->assertSame($samePriority, collect($samePriority)->sort()->values()->all());
        $this->assertSame('IR', collect($snapshot['security']['rules'])->firstWhere('match_type', 'country')['value']);
    }

    public function test_bounded_import_creates_one_revision_and_rolls_back_through_edge_history(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $before = $domain->revision;
        $rules = collect(range(1, 100))->map(fn (int $index): array => [
            'match_type' => 'ip', 'value' => "192.0.2.$index", 'action' => 'block', 'priority' => $index,
        ])->all();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules/import", ['replace_existing' => true, 'rules' => $rules])->assertAccepted();
        $this->assertSame($before + 1, $domain->refresh()->revision);
        $this->assertSame(100, $domain->securityRules()->count());
        (new ReconcileEdgeDomain($domain->id))->handle();
        $revision = $domain->refresh()->revision;

        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}/security/rules/".$domain->securityRules()->firstOrFail()->id)->assertAccepted();
        $this->assertSame(99, $domain->securityRules()->count());
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => $revision])->assertAccepted();
        $this->assertSame(100, $domain->securityRules()->count());
    }

    public function test_admin_restriction_quarantine_release_and_failed_target_preserve_active_placement(): void
    {
        Queue::fake();
        [, $domain] = $this->ownedDomain(true);
        $admin = User::factory()->admin()->create();
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $quarantine = EdgePool::query()->where('kind', 'quarantine')->firstOrFail();
        DomainEdgePlacement::query()->create(['domain_id' => $domain->id, 'active_pool_id' => $shared->id, 'desired_revision' => $domain->revision, 'state' => 'active']);

        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/restrict", ['reason' => 'request spike'])
            ->assertAccepted()->assertJsonPath('data.state', 'restricted');
        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/quarantine", ['reason' => 'continued spike'])
            ->assertAccepted()->assertJsonPath('data.target_pool_id', $quarantine->id);
        $placement = $domain->edgePlacement()->firstOrFail();
        $this->assertSame($shared->id, $placement->active_pool_id);
        $this->assertSame($quarantine->id, $placement->target_pool_id);
        $this->assertSame('deploying', $placement->state);
        $placement->update(['state' => 'failed', 'last_error' => 'candidate_validation_failed']);
        $this->assertSame($shared->id, $placement->refresh()->active_pool_id);

        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/release", [])->assertAccepted()
            ->assertJsonPath('data.state', 'recovering')->assertJsonPath('data.target_pool_id', $shared->id);
        $this->assertDatabaseHas('security_events', ['domain_id' => $domain->id, 'reason_code' => 'domain_quarantined']);
    }

    public function test_emergency_modes_are_bounded_async_idempotent_and_expire(): void
    {
        $admin = User::factory()->admin()->create();
        $edge = Edge::query()->create(['name' => 'security-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'ipv4' => '203.0.113.10', 'enabled' => true]);
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $cell = $edge->cells()->create(['edge_pool_id' => $shared->id, 'name' => $shared->name, 'status' => 'ready', 'service_ipv4' => '203.0.113.11']);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $response = $this->actingAs($admin)->withHeaders($headers)->postJson("/api/admin/edge-cells/{$cell->id}/emergency-mode", [
            'actions' => ['allow_get_head_only', 'disable_origin_retries'], 'duration_minutes' => 10,
        ])->assertAccepted();
        $this->actingAs($admin)->withHeaders($headers)->postJson("/api/admin/edge-cells/{$cell->id}/emergency-mode", [
            'actions' => ['allow_get_head_only', 'disable_origin_retries'], 'duration_minutes' => 10,
        ])->assertAccepted()->assertJsonPath('data.emergency_mode_id', $response->json('data.emergency_mode_id'));
        $this->assertSame(1, EmergencyMode::query()->count());
        $task = EdgeTask::query()->where('type', 'emergency_mode')->firstOrFail();
        $this->assertSame([$cell->name], $task->payload['cell_names']);
        $this->assertLessThanOrEqual(11, count($task->payload['actions']));

        EmergencyMode::query()->firstOrFail()->update(['expires_at' => now()->subMinute()]);
        $this->artisan('security:reconcile-readiness')->assertSuccessful();
        $this->assertFalse(EmergencyMode::query()->firstOrFail()->active);
        $this->assertSame(2, EdgeTask::query()->where('type', 'emergency_mode')->count());
    }

    public function test_pool_withdrawal_removes_only_that_pool_from_platform_dns(): void
    {
        $admin = User::factory()->admin()->create();
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $other = EdgePool::query()->create(['name' => 'shared-other', 'kind' => 'shared', 'enabled' => true]);
        $this->actingAs($admin)->postJson("/api/admin/edge-pools/{$pool->id}/withdraw")->assertAccepted();
        $this->assertTrue($pool->refresh()->withdrawn);
        $this->assertFalse($other->refresh()->withdrawn);
        $this->actingAs($admin)->postJson("/api/admin/edge-pools/{$pool->id}/restore")->assertAccepted();
        $this->assertFalse($pool->refresh()->withdrawn);
    }

    private function ownedDomain(bool $proxied = false): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'security.example.test', 'display_name' => 'Security', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user);
        if ($proxied) {
            $domain->dnsRecords()->create([
                'type' => 'A', 'name' => $domain->name, 'content' => '8.8.8.8', 'content_hash' => hash('sha256', 'origin'),
                'ttl' => 300, 'priority' => 0, 'weight' => 0, 'port' => 0, 'mode' => 'proxied',
                'origin' => ['scheme' => 'http', 'host' => '8.8.8.8', 'port' => 80, 'host_header' => $domain->name, 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => null],
            ]);
        }

        return [$user, $domain];
    }

    private function settings(string $profile): array
    {
        return [...SecurityConfig::defaults($profile), 'quarantine_policy' => 'automatic'];
    }
}
