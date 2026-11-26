<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShadowAndExpiringRulesTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): Domain
    {
        return Domain::query()->create(['name' => 'shadow.example', 'display_name' => 'shadow.example', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
    }

    private function rule(array $extra = []): array
    {
        return ['name' => 'Probe', 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/probe']], 'action' => 'block', 'priority' => 10, ...$extra];
    }

    public function test_a_rule_defaults_to_enforcing_without_an_expiry(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $this->actingAs(User::factory()->admin()->create())->postJson("/api/domains/{$domain->id}/security/rules", $this->rule())->assertCreated()->assertJsonPath('data.mode', 'enforce');

        $compiled = SecurityConfig::compile($domain)['rules'][0];
        $this->assertSame('enforce', $compiled['mode']);
        $this->assertNull($compiled['expires_at']);
    }

    public function test_a_shadow_rule_with_an_expiry_reaches_the_snapshot_as_a_unix_time_and_queues_one_reconcile(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $expires = now()->addDay()->startOfSecond();
        $this->actingAs(User::factory()->admin()->create())->postJson("/api/domains/{$domain->id}/security/rules", $this->rule(['mode' => 'shadow', 'expires_at' => $expires->toIso8601String()]))->assertCreated();

        $compiled = SecurityConfig::compile($domain)['rules'][0];
        $this->assertSame('shadow', $compiled['mode']);
        $this->assertSame($expires->getTimestamp(), $compiled['expires_at']);
        Queue::assertPushed(ReconcileEdgeDomain::class, 1);
    }

    public function test_a_rule_moves_from_shadow_to_enforce_in_one_update_and_bumps_the_revision(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $admin = User::factory()->admin()->create();
        $id = $this->actingAs($admin)->postJson("/api/domains/{$domain->id}/security/rules", $this->rule(['mode' => 'shadow']))->assertCreated()->json('data.id');
        $revision = $domain->fresh()->revision;

        $this->actingAs($admin)->patchJson("/api/domains/{$domain->id}/security/rules/$id", $this->rule(['mode' => 'enforce']))->assertAccepted();

        $this->assertSame('enforce', SecurityConfig::compile($domain)['rules'][0]['mode']);
        $this->assertSame($revision + 1, $domain->fresh()->revision);
    }

    public function test_only_block_and_challenge_rules_can_run_in_shadow_and_the_expiry_must_be_near_and_in_the_future(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $admin = User::factory()->admin()->create();
        $url = "/api/domains/{$domain->id}/security/rules";

        $this->actingAs($admin)->postJson($url, $this->rule(['mode' => 'shadow', 'action' => 'log']))->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->actingAs($admin)->postJson($url, $this->rule(['mode' => 'shadow', 'action' => 'allow']))->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->actingAs($admin)->postJson($url, $this->rule(['mode' => 'audit']))->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->actingAs($admin)->postJson($url, $this->rule(['expires_at' => now()->subMinute()->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $this->actingAs($admin)->postJson($url, $this->rule(['expires_at' => now()->addYears(2)->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $this->assertSame(0, $domain->securityRules()->count());
    }

    public function test_a_rate_limit_rule_can_run_in_shadow(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $rate = ['key' => 'client_ip', 'threshold' => 5, 'period_seconds' => 60, 'duration_seconds' => 60];
        $this->actingAs(User::factory()->admin()->create())->postJson("/api/domains/{$domain->id}/security/rules", $this->rule(['mode' => 'shadow', 'rate_limit' => $rate]))->assertCreated();

        $this->assertSame('shadow', SecurityConfig::compile($domain)['rules'][0]['mode']);
    }

    public function test_a_user_cannot_add_a_shadow_rule_to_a_domain_they_are_not_assigned(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $this->rule(['mode' => 'shadow']))->assertNotFound();
    }

    public function test_a_platform_rule_has_a_mode_and_an_expiry_too(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $admin = User::factory()->admin()->create();
        $id = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Probes', 'domain_ids' => [$domain->id]])->assertCreated()->json('data.id');
        $expires = now()->addHours(6)->startOfSecond();
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$id/rules", $this->rule(['mode' => 'shadow', 'expires_at' => $expires->toIso8601String()]))->assertCreated();

        $compiled = SecurityConfig::compile($domain)['platform_rules'][0];
        $this->assertSame('shadow', $compiled['mode']);
        $this->assertSame($expires->getTimestamp(), $compiled['expires_at']);
    }
}
