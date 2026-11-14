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
        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/security")->assertNotFound();
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

    private function ruleBody(string $field, string $value, string $action = 'block', int $priority = 10, string $operator = 'equals'): array
    {
        return ['conditions' => [['field' => $field, 'operator' => $operator, 'value' => $value]], 'action' => $action, 'priority' => $priority];
    }

    public function test_typed_rules_validate_normalize_and_order_deterministically(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $valid = [
            $this->ruleBody('client_ip', '192.0.2.1', 'block', 20),
            $this->ruleBody('client_ip', '2001:DB8::1', 'allow', 10),
            $this->ruleBody('client_ip', '2001:db8::/32', 'block', 10),
            $this->ruleBody('country', 'ir', 'block', 30),
            $this->ruleBody('continent', 'AS', 'allow', 40),
            ['name' => 'Log scrapers', 'action' => 'log', 'priority' => 50, 'conditions' => [
                ['field' => 'path', 'operator' => 'starts_with', 'value' => '/api/'],
                ['field' => 'header', 'name' => 'X-Token', 'operator' => 'contains', 'value' => 'abc'],
                ['field' => 'body_size', 'operator' => 'greater_than', 'value' => 1000],
                ['field' => 'asn', 'operator' => 'in_list', 'value' => [64500, 64501]],
                ['field' => 'cookie_present', 'name' => 'session', 'operator' => 'equals', 'value' => true],
            ]],
        ];
        foreach ($valid as $rule) {
            $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $rule)->assertCreated();
        }
        $this->assertSame('2001:db8::1', $domain->securityRules()->where('action', 'allow')->orderBy('priority')->first()->conditions[0]['value']);
        $this->assertSame('x-token', $domain->securityRules()->where('action', 'log')->first()->conditions[1]['name']);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('client_ip', '2001:db8::/129'))
            ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('country', 'XX'))
            ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('path', '/a', 'block', 1, 'greater_than'))
            ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.operator');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('nonsense', 'x'))
            ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.field');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('path', '/a', 'redirect'))
            ->assertUnprocessable()->assertJsonValidationErrors('action');

        (new ReconcileEdgeDomain($domain->id))->handle();
        $snapshot = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail()->snapshot;
        $this->assertSame([10, 10, 20, 30, 40, 50], collect($snapshot['security']['rules'])->pluck('priority')->all());
        $samePriority = collect($snapshot['security']['rules'])->where('priority', 10)->pluck('id')->all();
        $this->assertSame($samePriority, collect($samePriority)->sort()->values()->all());
        $this->assertSame('IR', collect($snapshot['security']['rules'])->firstWhere('priority', 30)['conditions'][0]['value']);
    }

    public function test_attack_score_fields_accept_one_to_ninety_nine_only(): void
    {
        [$user, $domain] = $this->ownedDomain();
        foreach (['attack_score', 'attack_score_sqli', 'attack_score_xss', 'attack_score_rce'] as $index => $field) {
            $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody($field, '19', 'block', $index + 1, 'less_than'))->assertCreated();
        }
        foreach ([0, 100, -3] as $bad) {
            $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('attack_score', (string) $bad, 'block', 9, 'less_than'))
                ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');
        }
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('attack_score', '20', 'log', 9, 'starts_with'))
            ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.operator');
        $this->assertSame(19, $domain->securityRules()->where('priority', 1)->firstOrFail()->conditions[0]['value']);
    }

    public function test_rate_limit_rules_validate_their_typed_limit_and_are_bounded(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $rate = ['key' => 'client_ip_path', 'threshold' => 100, 'period_seconds' => 60, 'duration_seconds' => 300];
        $body = ['name' => 'Login brute force', 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/login']], 'action' => 'block', 'priority' => 10, 'rate_limit' => $rate];
        $id = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $body)->assertCreated()->json('data.id');
        $this->assertSame($rate, $domain->securityRules()->findOrFail($id)->rate_limit);

        // Counting every request of the domain is a rate-limit rule with no conditions; any other rule needs a condition.
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", ['conditions' => [], 'action' => 'log', 'priority' => 20, 'rate_limit' => ['key' => 'header', 'header_name' => 'X-Api-Key', 'threshold' => 5, 'period_seconds' => 10, 'duration_seconds' => 10]])
            ->assertCreated();
        $this->assertSame('x-api-key', $domain->securityRules()->where('priority', 20)->firstOrFail()->rate_limit['header_name']);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", ['conditions' => [], 'action' => 'block', 'priority' => 21])->assertUnprocessable()->assertJsonValidationErrors('conditions');

        $bad = [
            ['rate_limit' => [...$rate, 'period_seconds' => 30], 'field' => 'rate_limit.period_seconds'],
            ['rate_limit' => [...$rate, 'threshold' => 0], 'field' => 'rate_limit.threshold'],
            ['rate_limit' => [...$rate, 'duration_seconds' => 5], 'field' => 'rate_limit.duration_seconds'],
            ['rate_limit' => [...$rate, 'key' => 'country'], 'field' => 'rate_limit.key'],
            ['rate_limit' => [...$rate, 'key' => 'header'], 'field' => 'rate_limit.header_name'],
            ['rate_limit' => $rate, 'action' => 'allow', 'field' => 'action'],
            ['rate_limit' => $rate, 'action' => 'skip_managed', 'field' => 'action'],
        ];
        foreach ($bad as $index => $case) {
            $payload = [...$body, 'priority' => 100 + $index, 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => "/p$index"]], 'rate_limit' => $case['rate_limit'], 'action' => $case['action'] ?? 'block'];
            $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $payload)->assertUnprocessable()->assertJsonValidationErrors($case['field']);
        }

        // The same conditions and action with a different limit is a different rule; the cap on rate-limit rules is enforced.
        config(['security.maximum_rate_limit_rules' => 3]);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", [...$body, 'priority' => 30, 'rate_limit' => [...$rate, 'threshold' => 200]])->assertCreated();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", [...$body, 'priority' => 31, 'rate_limit' => [...$rate, 'threshold' => 300]])->assertStatus(409);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('country', 'DE', 'block', 32))->assertCreated();
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security/rules/$id", [...$body, 'rate_limit' => [...$rate, 'threshold' => 150]])->assertAccepted();
        $this->assertSame(150, $domain->securityRules()->findOrFail($id)->rate_limit['threshold']);
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security/rules/$id", [...$body, 'rate_limit' => null])->assertAccepted();
        $this->assertNull($domain->securityRules()->findOrFail($id)->rate_limit);

        (new ReconcileEdgeDomain($domain->id))->handle();
        $snapshot = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail()->snapshot;
        $this->assertContains(200, collect($snapshot['security']['rules'])->pluck('rate_limit.threshold')->all());
    }

    public function test_rule_bounds_duplicates_and_permissions(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $tooMany = ['action' => 'block', 'priority' => 1, 'conditions' => array_fill(0, 6, ['field' => 'method', 'operator' => 'equals', 'value' => 'GET'])];
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $tooMany)->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $longList = $this->ruleBody('asn', '1');
        $longList['conditions'][0] = ['field' => 'asn', 'operator' => 'in_list', 'value' => range(1, 101)];
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $longList)->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", ['action' => 'block', 'priority' => 1, 'conditions' => []])->assertUnprocessable();

        $first = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('country', 'ir'))->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('country', 'IR', 'block', 99))
            ->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $other = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('country', 'DE'))->assertCreated()->json('data.id');
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security/rules/$other", $this->ruleBody('country', 'ir'))
            ->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security/rules/$first", [...$this->ruleBody('country', 'IR'), 'enabled' => false])->assertAccepted();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('country', 'FR'))->assertNotFound();
        $this->actingAs($outsider)->putJson("/api/domains/{$domain->id}/security/rules/order", ['order' => [$first, $other]])->assertNotFound();
    }

    public function test_reorder_and_preview(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $ids = [];
        foreach (['IR', 'DE', 'FR'] as $index => $country) {
            $ids[] = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->ruleBody('country', $country, 'block', ($index + 1) * 100))->json('data.id');
        }
        $before = $domain->refresh()->revision;
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/security/rules/order", ['order' => [$ids[2], $ids[0], $ids[1]]])->assertAccepted();
        $this->assertSame($before + 1, $domain->refresh()->revision);
        $this->assertSame([$ids[2], $ids[0], $ids[1]], $domain->securityRules()->orderBy('priority')->pluck('id')->all());
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/security/rules/order", ['order' => [$ids[2], $ids[0]]])->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/security/rules/order", ['order' => [$ids[2], $ids[0], $ids[1], 999999]])->assertUnprocessable();
        $this->assertSame($before + 1, $domain->refresh()->revision);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules/preview", $this->ruleBody('country', 'DE', 'block', 25))
            ->assertOk()->assertJsonPath('data.position', 3)->assertJsonPath('data.duplicate_of', $ids[1])->assertJsonPath('data.rule.conditions.0.value', 'DE');
        $this->assertSame(3, $domain->securityRules()->count());
        $this->assertSame($before + 1, $domain->refresh()->revision);
    }

    public function test_bounded_import_creates_one_revision_and_rolls_back_through_edge_history(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $before = $domain->revision;
        $rules = collect(range(1, 100))->map(fn (int $index): array => $this->ruleBody('client_ip', "192.0.2.$index", 'block', $index))->all();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules/import", ['replace_existing' => true, 'rules' => $rules])->assertAccepted();
        $this->assertSame($before + 1, $domain->refresh()->revision);
        $this->assertSame(100, $domain->securityRules()->count());
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules/import", ['rules' => [$rules[0], $rules[0]]])->assertUnprocessable();
        (new ReconcileEdgeDomain($domain->id))->handle();
        $revision = $domain->refresh()->revision;

        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}/security/rules/".$domain->securityRules()->firstOrFail()->id)->assertAccepted();
        $this->assertSame(99, $domain->securityRules()->count());
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => $revision])->assertAccepted();
        $this->assertSame(100, $domain->securityRules()->count());
    }

    public function test_admin_quarantine_recovery_and_failed_target_preserve_active_placement(): void
    {
        Queue::fake();
        [, $domain] = $this->ownedDomain(true);
        $admin = User::factory()->admin()->create();
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $quarantine = EdgePool::query()->where('kind', 'quarantine')->firstOrFail();
        DomainEdgePlacement::query()->create(['domain_id' => $domain->id, 'active_pool_id' => $shared->id, 'desired_revision' => $domain->revision, 'state' => 'active']);

        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/protect", ['reason' => 'request spike'])->assertNotFound();
        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/quarantine", ['reason' => 'continued spike'])
            ->assertAccepted()->assertJsonPath('data.target_pool_id', $quarantine->id);
        $placement = $domain->edgePlacement()->firstOrFail();
        $this->assertSame($shared->id, $placement->active_pool_id);
        $this->assertSame($quarantine->id, $placement->target_pool_id);
        $this->assertSame('deploying', $placement->state);
        $placement->update(['state' => 'failed', 'last_error' => 'candidate_validation_failed']);
        $this->assertSame($shared->id, $placement->refresh()->active_pool_id);

        $this->actingAs($admin)->postJson("/api/admin/domains/{$domain->id}/return-to-normal", [])->assertAccepted()
            ->assertJsonPath('data.state', 'recovering')->assertJsonPath('data.target_pool_id', $shared->id);
        $this->assertDatabaseHas('security_events', ['domain_id' => $domain->id, 'reason_code' => 'domain_quarantined']);
    }

    public function test_pool_maintenance_is_bounded_async_idempotent_and_expires(): void
    {
        $admin = User::factory()->admin()->create();
        $edge = Edge::query()->create(['name' => 'security-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true]);
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $cell = $edge->cells()->create(['slot' => 1, 'edge_pool_id' => $shared->id, 'status' => 'ready']);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $response = $this->actingAs($admin)->withHeaders($headers)->postJson("/api/admin/edge-pools/{$shared->id}/maintenance", [
            'duration_minutes' => 10,
        ])->assertAccepted();
        $this->actingAs($admin)->withHeaders($headers)->postJson("/api/admin/edge-pools/{$shared->id}/maintenance", [
            'duration_minutes' => 10,
        ])->assertAccepted()->assertJsonPath('data.maintenance_id', $response->json('data.maintenance_id'));
        $this->assertSame(1, EmergencyMode::query()->count());
        $task = EdgeTask::query()->where('type', 'emergency_mode')->firstOrFail();
        $this->assertSame([$cell->name], $task->payload['cell_names']);
        $this->assertSame(['return_maintenance_response'], $task->payload['actions']);

        EmergencyMode::query()->firstOrFail()->update(['expires_at' => now()->subMinute()]);
        $this->artisan('cdnf:security:reconcile-readiness')->assertSuccessful();
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
        $domain = Domain::query()->create(['name' => 'security.example.test', 'display_name' => 'Security', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
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
