<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RuleMatchCountsAndImpactTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): Domain
    {
        return Domain::query()->create(['name' => 'counts.example', 'display_name' => 'counts.example', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
    }

    private function createRule(Domain $domain, string $path, int $priority): int
    {
        Queue::fake();
        $payload = ['name' => $path, 'conditions' => [$this->condition('path', 'equals', $path)], 'action' => 'block', 'priority' => $priority];

        return (int) $this->actingAs(User::factory()->admin()->create())->postJson("/api/domains/{$domain->id}/security/rules", $payload)->assertCreated()->json('data.id');
    }

    private function condition(string $field, string $operator, mixed $value): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    public function test_the_rule_list_carries_enforced_and_shadow_counts_by_rule(): void
    {
        $domain = $this->domain();
        $enforced = $this->createRule($domain, '/one', 10);
        $quiet = $this->createRule($domain, '/two', 20);
        Http::fake([config('services.clickhouse.url').'*' => Http::response(implode("\n", [
            json_encode(['rule_ref' => 'r'.$enforced, 'mode' => 'enforce', 'requests' => '12']),
            json_encode(['rule_ref' => 'r'.$enforced, 'mode' => 'shadow', 'requests' => '3']),
        ]))]);

        $rows = collect($this->actingAs(User::factory()->admin()->create())->getJson("/api/domains/{$domain->id}/security/rules")->assertOk()->json('data.data'))->keyBy('id');

        $this->assertSame(['enforce' => 12, 'shadow' => 3], $rows[$enforced]['matches_last_24_hours']);
        $this->assertSame(['enforce' => 0, 'shadow' => 0], $rows[$quiet]['matches_last_24_hours']);
    }

    public function test_an_unavailable_rollup_gives_unknown_counts_not_zero(): void
    {
        $domain = $this->domain();
        $rule = $this->createRule($domain, '/one', 10);
        Http::fake([config('services.clickhouse.url').'*' => Http::response('down', 500)]);

        $row = $this->actingAs(User::factory()->admin()->create())->getJson("/api/domains/{$domain->id}/security/rules")->assertOk()->json('data.data.0');

        $this->assertSame($rule, $row['id']);
        $this->assertNull($row['matches_last_24_hours']);
    }

    public function test_the_estimate_counts_over_stored_fields_and_is_labeled_partial(): void
    {
        $domain = $this->domain();
        Http::fake([config('services.clickhouse.url').'*' => Http::response(json_encode(['examined' => '500', 'matched' => '42']))]);

        $this->actingAs(User::factory()->admin()->create())->postJson("/api/domains/{$domain->id}/security/rules/estimate", [
            'conditions' => [$this->condition('path', 'starts_with', '/admin'), $this->condition('country', 'in_list', ['DE', 'FR'])], 'action' => 'block', 'priority' => 5,
        ])->assertOk()->assertJsonPath('data.partial', true)->assertJsonPath('data.matched', 42)->assertJsonPath('data.matched_at_most', 42)
            ->assertJsonPath('data.events_examined', 500)->assertJsonPath('data.unknown_fields', []);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), "startsWith(splitByChar('?', path)[1], {p0:String})")
            && str_contains($request->body(), 'country IN {p1:Array(String)}')
            && str_contains(urldecode($request->url()), "param_p1=['DE','FR']"));
    }

    public function test_conditions_on_unstored_fields_are_unknown_and_the_count_is_only_an_upper_bound(): void
    {
        $domain = $this->domain();
        Http::fake([config('services.clickhouse.url').'*' => Http::response(json_encode(['examined' => '500', 'matched' => '42']))]);

        $this->actingAs(User::factory()->admin()->create())->postJson("/api/domains/{$domain->id}/security/rules/estimate", [
            'conditions' => [$this->condition('path', 'equals', '/login'), ['field' => 'header', 'name' => 'x-token', 'operator' => 'equals', 'value' => 'abc']], 'action' => 'block', 'priority' => 5,
        ])->assertOk()->assertJsonPath('data.partial', true)->assertJsonPath('data.matched', null)->assertJsonPath('data.matched_at_most', 42)->assertJsonPath('data.unknown_fields', ['header']);
    }

    public function test_the_estimate_needs_permission_on_the_domain_and_valid_input(): void
    {
        $domain = $this->domain();
        $other = User::factory()->create();
        $payload = ['conditions' => [$this->condition('path', 'equals', '/x')], 'action' => 'block', 'priority' => 5];

        $this->actingAs($other)->postJson("/api/domains/{$domain->id}/security/rules/estimate", $payload)->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->postJson("/api/domains/{$domain->id}/security/rules/estimate", ['conditions' => [], 'action' => 'block'])->assertUnprocessable();
    }
}
