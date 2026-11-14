<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\ReconcilePlatformRulesets;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\PlatformRuleset;
use App\Models\User;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlatformRulesetsTest extends TestCase
{
    use RefreshDatabase;

    private function domain(string $name): Domain
    {
        return Domain::query()->create(['name' => $name, 'display_name' => $name, 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
    }

    private function rule(string $path = '/wp-login.php', string $action = 'block'): array
    {
        return ['name' => 'Block login probes', 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => $path]], 'action' => $action, 'priority' => 10];
    }

    public function test_an_administrator_applies_a_ruleset_to_chosen_domains_and_removing_it_restores_them(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        [$one, $two, $three] = [$this->domain('one.example'), $this->domain('two.example'), $this->domain('three.example')];

        $created = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Known probes', 'description' => 'Scanner paths', 'domain_ids' => [$one->id, $two->id]])->assertCreated();
        $id = $created->json('data.id');
        $this->assertNotNull($created->json('data.operation_id'));
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$id/rules", $this->rule())->assertCreated();

        foreach ([$one, $two] as $domain) {
            $rules = SecurityConfig::compile($domain)['platform_rules'];
            $this->assertCount(1, $rules);
            $this->assertSame('Known probes', $rules[0]['ruleset']);
            $this->assertStringStartsWith('p', $rules[0]['id']);
            $this->assertSame('/wp-login.php', $rules[0]['conditions'][0]['value']);
        }
        $this->assertSame([], SecurityConfig::compile($three)['platform_rules']);

        // Disabling the ruleset, and later deleting it, takes it away from both domains again.
        $this->actingAs($admin)->patchJson("/api/admin/platform-rulesets/$id", ['enabled' => false])->assertAccepted();
        $this->assertSame([], SecurityConfig::compile($one)['platform_rules']);
        $this->actingAs($admin)->patchJson("/api/admin/platform-rulesets/$id", ['enabled' => true])->assertAccepted();
        $this->assertCount(1, SecurityConfig::compile($two)['platform_rules']);
        $this->actingAs($admin)->deleteJson("/api/admin/platform-rulesets/$id")->assertAccepted();
        $this->assertSame([], SecurityConfig::compile($one)['platform_rules']);
        $this->assertSame([], SecurityConfig::compile($two)['platform_rules']);
        $this->assertSame(0, PlatformRuleset::query()->count());
    }

    public function test_a_ruleset_for_all_domains_reaches_every_domain_and_orders_platform_rules_first(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('all.example');
        $domain->securityRules()->create(['action' => 'block', 'priority' => 1, 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/own']], 'fingerprint' => 'own']);
        $a = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'First', 'applies_to_all' => true])->json('data.id');
        $b = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Second', 'applies_to_all' => true])->json('data.id');
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$b/rules", [...$this->rule('/b'), 'priority' => 1])->assertCreated();
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$a/rules", [...$this->rule('/a2'), 'priority' => 20])->assertCreated();
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$a/rules", [...$this->rule('/a1'), 'priority' => 10, 'action' => 'log'])->assertCreated();

        $compiled = SecurityConfig::compile($domain);
        $this->assertSame(['/a1', '/a2', '/b'], collect($compiled['platform_rules'])->pluck('conditions.0.value')->all());
        $this->assertSame(['/own'], collect($compiled['rules'])->pluck('conditions.0.value')->all());
    }

    public function test_domain_owners_see_names_and_match_counts_but_never_the_rules(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $domain = $this->domain('owned.example');
        $domain->users()->attach($owner->id);
        $other = $this->domain('other.example');
        $id = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Provider rules', 'description' => 'Known bad paths', 'domain_ids' => [$domain->id]])->json('data.id');
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$id/rules", $this->rule('/secret-path-value'))->assertCreated();
        $domain->securityEvents()->create(['reason_code' => 'platform_rule_block', 'occurred_at' => now()->subHour(), 'details' => ['count' => 7]]);
        $domain->securityEvents()->create(['reason_code' => 'platform_rule_log', 'occurred_at' => now()->subHours(2), 'details' => ['count' => 3]]);
        $domain->securityEvents()->create(['reason_code' => 'platform_rule_block', 'occurred_at' => now()->subDays(3), 'details' => ['count' => 100]]);
        $domain->securityEvents()->create(['reason_code' => 'custom_rule_block', 'occurred_at' => now()->subHour(), 'details' => ['count' => 50]]);

        $response = $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/security/platform-rulesets")->assertOk();
        $this->assertSame([['id' => $id, 'name' => 'Provider rules', 'description' => 'Known bad paths']], $response->json('data.rulesets'));
        $this->assertSame(10, $response->json('data.matches_last_24_hours'));
        $this->assertStringNotContainsString('secret-path-value', $response->getContent());
        $this->actingAs($owner)->getJson('/api/admin/platform-rulesets')->assertForbidden();
        $this->actingAs($owner)->getJson("/api/admin/platform-rulesets/$id/rules")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/domains/{$other->id}/security/platform-rulesets")->assertNotFound();
        // The rules never appear in what a domain owner can read about the domain's own security either.
        $this->assertStringNotContainsString('secret-path-value', $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/security")->getContent());
    }

    public function test_changes_reach_exactly_the_domains_the_ruleset_touched(): void
    {
        $admin = User::factory()->admin()->create();
        [$one, $two, $three] = [$this->domain('a.example'), $this->domain('b.example'), $this->domain('c.example')];
        Queue::fake();
        $created = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Moving', 'domain_ids' => [$one->id, $two->id]]);
        $id = $created->json('data.id');
        Queue::assertPushed(ReconcilePlatformRulesets::class);
        $operation = Operation::query()->findOrFail($created->json('data.operation_id'));
        $this->assertSame(['all' => false, 'domain_ids' => [$one->id, $two->id]], ['all' => $operation->input['all'], 'domain_ids' => $operation->input['domain_ids']]);
        (new ReconcilePlatformRulesets($operation->id))->handle();
        Queue::assertPushed(ReconcileEdgeDomain::class, 2);
        $this->assertSame('succeeded', $operation->refresh()->status);

        // Moving the ruleset from {a, b} to {b, c} reconciles a (it loses the ruleset), b, and c.
        Queue::fake();
        $moved = $this->actingAs($admin)->patchJson("/api/admin/platform-rulesets/$id", ['domain_ids' => [$two->id, $three->id]])->assertAccepted();
        $operation = Operation::query()->findOrFail($moved->json('data.operation_id'));
        $this->assertEqualsCanonicalizing([$one->id, $two->id, $three->id], $operation->input['domain_ids']);
        (new ReconcilePlatformRulesets($operation->id))->handle();
        Queue::assertPushed(ReconcileEdgeDomain::class, 3);

        // A ruleset for every domain reconciles the proxied ones, in the same bounded chunks as any global change.
        Queue::fake();
        $all = $this->actingAs($admin)->patchJson("/api/admin/platform-rulesets/$id", ['applies_to_all' => true, 'domain_ids' => []])->assertAccepted();
        $this->assertTrue(Operation::query()->findOrFail($all->json('data.operation_id'))->input['all']);
    }

    public function test_validation_and_bounds(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('bounds.example');
        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Both', 'applies_to_all' => true, 'domain_ids' => [$domain->id]])->assertUnprocessable()->assertJsonValidationErrors('domain_ids');
        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Ghost', 'domain_ids' => [999999]])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Many', 'domain_ids' => range(1, 501)])->assertUnprocessable();
        $first = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Same'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Same'])->assertUnprocessable()->assertJsonValidationErrors('name');

        // Rules go through the same typed validation as domain rules, duplicates are refused, and a rate limit is allowed.
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$first/rules", ['conditions' => [['field' => 'nonsense', 'operator' => 'equals', 'value' => 'x']], 'action' => 'block', 'priority' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.field');
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$first/rules", $this->rule())->assertCreated();
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$first/rules", $this->rule())->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$first/rules", ['conditions' => [], 'action' => 'block', 'priority' => 2,
            'rate_limit' => ['key' => 'client_ip', 'threshold' => 100, 'period_seconds' => 60, 'duration_seconds' => 300]])->assertCreated();

        config(['security.maximum_platform_rules_per_ruleset' => 2]);
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$first/rules", $this->rule('/third'))->assertStatus(409);
        config(['security.maximum_platform_rulesets' => 1]);
        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Second'])->assertStatus(409);
        $this->assertSame(2, $this->actingAs($admin)->getJson("/api/admin/platform-rulesets/$first")->json('data.rules_count'));
    }
}
