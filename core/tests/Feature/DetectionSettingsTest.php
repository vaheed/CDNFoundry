<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DetectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'ai.example', 'display_name' => 'ai.example', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    public function test_sensitive_data_kinds_are_saved_revisioned_compiled_and_removed(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/sensitive-data";

        $this->actingAs($user)->putJson($url, ['kinds' => ['iban', 'card']])->assertAccepted();
        $domain->refresh();
        $this->assertSame(['card', 'iban'], $domain->sensitive_data_check['kinds']);
        $this->assertSame(2, $domain->revision);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.sensitive_data_check_updated', 'subject_id' => (string) $domain->id]);
        Queue::assertPushed(ReconcileEdgeDomain::class);
        $compiled = SecurityConfig::compile($domain)['sensitive_data'];
        $this->assertSame(['card', 'iban'], $compiled['kinds']);
        $this->assertSame(16384, $compiled['response_bytes']);

        $this->actingAs($user)->deleteJson($url)->assertAccepted();
        $this->assertNull($domain->refresh()->sensitive_data_check);
        $this->assertSame([], SecurityConfig::compile($domain)['sensitive_data']['kinds']);
    }

    public function test_sensitive_data_refuses_unknown_kinds_and_strangers(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/sensitive-data";

        $this->actingAs($user)->putJson($url, ['kinds' => []])->assertUnprocessable();
        $this->actingAs($user)->putJson($url, ['kinds' => ['passport']])->assertUnprocessable()->assertJsonValidationErrors('kinds.0');
        $this->actingAs(User::factory()->create())->putJson($url, ['kinds' => ['card']])->assertNotFound();
        $this->assertNull($domain->refresh()->sensitive_data_check);
        Queue::assertNothingPushed();
    }

    public function test_ai_endpoints_and_the_prompt_field_are_validated_saved_and_compiled(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/ai-protection";

        $this->actingAs($user)->putJson($url, ['endpoints' => ['/v1/chat', '/v1/chat', '/api/ask'], 'prompt_field' => 'input.prompt'])->assertAccepted();
        $domain->refresh();
        $this->assertSame(['/api/ask', '/v1/chat'], $domain->ai_protection['endpoints']);
        $compiled = SecurityConfig::compile($domain)['ai_protection'];
        $this->assertSame('input.prompt', $compiled['prompt_field']);
        $this->assertSame(16384, $compiled['body_bytes']);

        foreach ([
            ['endpoints' => ['chat'], 'prompt_field' => 'prompt'],
            ['endpoints' => ['/chat?x=1'], 'prompt_field' => 'prompt'],
            ['endpoints' => ['/chat'], 'prompt_field' => 'a b'],
            ['endpoints' => ['/chat'], 'prompt_field' => 'a.b.c.d.e'],
            ['endpoints' => [], 'prompt_field' => 'prompt'],
            ['endpoints' => ['/1', '/2', '/3', '/4', '/5', '/6'], 'prompt_field' => 'prompt'],
        ] as $body) {
            $this->actingAs($user)->putJson($url, $body)->assertUnprocessable();
        }
        $this->actingAs($user)->deleteJson($url)->assertAccepted();
        $this->assertNull($domain->refresh()->ai_protection);
    }

    public function test_the_prompt_fields_are_rule_fields_with_the_right_operators(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $rule = fn (string $field, $value, string $operator, int $priority) => ['name' => $field, 'action' => 'block', 'priority' => $priority, 'conditions' => [['field' => $field, 'operator' => $operator, 'value' => $value]]];
        $post = fn (array $body) => $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $body);

        $post($rule('prompt_injection_score', '30', 'less_than', 1))->assertUnprocessable();
        $post($rule('prompt_pii', true, 'equals', 3))->assertUnprocessable();
        $domain->update(['ai_protection' => ['endpoints' => ['/v1/chat'], 'prompt_field' => 'messages']]);
        $post($rule('prompt_injection_score', '30', 'less_than', 1))->assertCreated();
        $post($rule('prompt_injection_score', '100', 'less_than', 2))->assertUnprocessable();
        $post($rule('prompt_pii', true, 'equals', 3))->assertCreated();
        $post($rule('prompt_pii', 'yes', 'equals', 4))->assertUnprocessable();
    }
}
