<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePlatformRulesets;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\User;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StagedPlatformRulesetsTest extends TestCase
{
    use RefreshDatabase;

    private function domain(string $name): Domain
    {
        return Domain::query()->create(['name' => $name, 'display_name' => $name, 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
    }

    /** @return array<string, string> rule action => the mode the domain's snapshot gives it */
    private function modes(Domain $domain): array
    {
        return collect(SecurityConfig::compile($domain)['platform_rules'])->mapWithKeys(fn (array $rule): array => [$rule['action'] => $rule['mode']])->all();
    }

    private function ruleset(User $admin, array $extra): int
    {
        $id = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Staged', 'applies_to_all' => true, ...$extra])->assertCreated()->json('data.id');
        foreach (['block' => '/a', 'challenge' => '/b', 'log' => '/c'] as $action => $path) {
            $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$id/rules", ['conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => $path]], 'action' => $action, 'priority' => 10])->assertCreated();
        }

        return $id;
    }

    public function test_a_ruleset_rolls_out_from_shadow_to_a_chosen_subset_to_every_domain(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        [$chosen, $other] = [$this->domain('chosen.example'), $this->domain('other.example')];
        $id = $this->ruleset($admin, ['rollout_stage' => 'shadow']);

        // Shadow: its blocking and challenging rules only record, everywhere; a log rule acts as written.
        foreach ([$chosen, $other] as $domain) {
            $this->assertSame(['block' => 'shadow', 'challenge' => 'shadow', 'log' => 'enforce'], $this->modes($domain));
        }

        // Subset: it enforces on the chosen domain and keeps recording on the rest.
        $this->actingAs($admin)->patchJson("/api/admin/platform-rulesets/$id", ['rollout_stage' => 'subset', 'enforced_domain_ids' => [$chosen->id]])->assertAccepted()->assertJsonPath('data.rollout_stage', 'subset');
        $this->assertSame(['block' => 'enforce', 'challenge' => 'enforce', 'log' => 'enforce'], $this->modes($chosen));
        $this->assertSame(['block' => 'shadow', 'challenge' => 'shadow', 'log' => 'enforce'], $this->modes($other));

        // All: enforced everywhere.
        $this->actingAs($admin)->patchJson("/api/admin/platform-rulesets/$id", ['rollout_stage' => 'all'])->assertAccepted();
        $this->assertSame(['block' => 'enforce', 'challenge' => 'enforce', 'log' => 'enforce'], $this->modes($other));

        // Going back to shadow is one saved change, the way back from a stage that found a problem.
        $this->actingAs($admin)->patchJson("/api/admin/platform-rulesets/$id", ['rollout_stage' => 'shadow'])->assertAccepted();
        $this->assertSame('shadow', $this->modes($chosen)['block']);
    }

    public function test_each_stage_change_queues_one_chunked_rollout_for_the_whole_reach(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->domain('one.example');
        $id = $this->ruleset($admin, ['rollout_stage' => 'shadow']);
        Queue::fake();

        $response = $this->actingAs($admin)->patchJson("/api/admin/platform-rulesets/$id", ['rollout_stage' => 'subset', 'enforced_domain_ids' => []])->assertAccepted();

        Queue::assertPushed(ReconcilePlatformRulesets::class, 1);
        $this->assertSame('edge.platform_ruleset_reconcile', Operation::query()->findOrFail($response->json('data.operation_id'))->type);
    }

    public function test_a_rule_that_is_already_shadow_stays_shadow_at_every_stage(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('shadowrule.example');
        $id = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Own shadow', 'applies_to_all' => true, 'rollout_stage' => 'all'])->json('data.id');
        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$id/rules", ['conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/x']], 'action' => 'block', 'priority' => 1, 'mode' => 'shadow'])->assertCreated();

        $this->assertSame(['block' => 'shadow'], $this->modes($domain));
    }

    public function test_a_stage_is_validated_and_enforcement_domains_must_be_in_the_ruleset_reach(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        [$inside, $outside] = [$this->domain('inside.example'), $this->domain('outside.example')];

        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Bad', 'rollout_stage' => 'half'])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Listed', 'domain_ids' => [$inside->id], 'rollout_stage' => 'subset', 'enforced_domain_ids' => [$outside->id]])->assertUnprocessable()->assertJsonValidationErrors('enforced_domain_ids');
        $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Listed', 'domain_ids' => [$inside->id], 'rollout_stage' => 'subset', 'enforced_domain_ids' => [$inside->id]])->assertCreated();
    }

    public function test_only_administrators_change_a_rollout_stage(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create())->postJson('/api/admin/platform-rulesets', ['name' => 'Nope', 'rollout_stage' => 'shadow'])->assertForbidden();
    }
}
