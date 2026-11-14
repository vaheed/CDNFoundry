<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\SecurityPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityPresetsTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'presets.example', 'display_name' => 'Presets', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    public function test_user_agent_preset_writes_one_rule_per_value_and_replaces_as_a_unit(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/presets/user-agent";

        $this->actingAs($user)->putJson($url, ['values' => ['sqlmap', 'BadBot', 'badbot', ' Nikto '], 'action' => 'block'])->assertAccepted();
        $rules = $domain->securityRules()->where('preset', 'user_agent')->orderBy('priority')->get();
        $this->assertSame(['sqlmap', 'BadBot', 'Nikto'], $rules->map(fn ($rule) => $rule->conditions[0]['value'])->all());
        $this->assertSame(['user_agent'], $rules->pluck('conditions.0.field')->unique()->values()->all());
        $this->assertSame(2, $domain->refresh()->revision);

        // Saving again replaces the set in one revision, keeps rules the user wrote, and can challenge instead.
        $own = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", ['conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/x']], 'action' => 'log', 'priority' => 1])->json('data.id');
        $this->actingAs($user)->putJson($url, ['values' => ['curl'], 'action' => 'challenge'])->assertAccepted();
        $this->assertSame(['curl'], $domain->securityRules()->where('preset', 'user_agent')->get()->map(fn ($rule) => $rule->conditions[0]['value'])->all());
        $this->assertSame('challenge', $domain->securityRules()->where('preset', 'user_agent')->firstOrFail()->action);
        $this->assertTrue($domain->securityRules()->whereKey($own)->exists());
        $this->assertSame(['values' => ['curl'], 'action' => 'challenge', 'enabled' => true], $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/presets")->json('data.user_agent'));

        $this->actingAs($user)->deleteJson($url)->assertAccepted();
        $this->assertSame(0, $domain->securityRules()->where('preset', 'user_agent')->count());
        $this->assertNull($this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/presets")->json('data.user_agent'));
        Queue::assertPushed(ReconcileEdgeDomain::class);
    }

    public function test_user_agent_preset_validates_and_is_bounded(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/presets/user-agent";
        foreach ([['values' => [], 'action' => 'block'], ['values' => ['x'], 'action' => 'allow'], ['values' => ['x'], 'action' => 'log'], ['values' => [str_repeat('a', 101)], 'action' => 'block'],
            ['values' => array_map(fn (int $i): string => "bot$i", range(1, 51)), 'action' => 'block'], ['values' => ['   '], 'action' => 'block'], ['action' => 'block']] as $body) {
            $this->actingAs($user)->putJson($url, $body)->assertUnprocessable();
        }
        $this->assertSame(0, $domain->securityRules()->count());
        $this->assertSame(1, $domain->refresh()->revision);
        $this->actingAs($user)->putJson($url, ['values' => array_map(fn (int $i): string => "bot$i", range(1, 50)), 'action' => 'block'])->assertAccepted();
        $this->assertSame(50, $domain->securityRules()->count());

        $this->actingAs(User::factory()->create())->putJson($url, ['values' => ['x'], 'action' => 'block'])->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson("/api/domains/{$domain->id}/security/presets")->assertNotFound();
    }

    public function test_hotlink_preset_is_one_rule_that_always_allows_the_domain_itself(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/presets/hotlink";

        $this->actingAs($user)->putJson($url, ['extensions' => ['JPG', '.png', 'png'], 'allowed_hosts' => ['Partner.example', 'cdn.example']])->assertAccepted();
        $rule = $domain->securityRules()->where('preset', 'hotlink')->firstOrFail();
        $this->assertSame('block', $rule->action);
        $this->assertSame(['field' => 'file_extension', 'operator' => 'in_list', 'value' => ['jpg', 'png']], $rule->conditions[0]);
        $this->assertSame(['field' => 'referer_present', 'operator' => 'equals', 'value' => true], $rule->conditions[1]);
        $this->assertSame('referer_host', $rule->conditions[2]['field']);
        $this->assertSame('not_in_list', $rule->conditions[2]['operator']);
        $this->assertEqualsCanonicalizing(['partner.example', 'cdn.example', 'presets.example', 'www.presets.example'], $rule->conditions[2]['value']);
        $this->assertSame(['extensions' => ['jpg', 'png'], 'allowed_hosts' => ['partner.example', 'cdn.example'], 'enabled' => true], $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/presets")->json('data.hotlink'));

        $this->actingAs($user)->putJson($url, ['extensions' => ['gif']])->assertAccepted();
        $this->assertSame(1, $domain->securityRules()->where('preset', 'hotlink')->count());
        foreach ([['extensions' => []], ['extensions' => ['not a ext']], ['extensions' => ['jpg'], 'allowed_hosts' => ['bad host!']], ['extensions' => ['jpg'], 'allowed_hosts' => array_fill(0, 49, 'a.example')]] as $bad) {
            $this->actingAs($user)->putJson($url, $bad)->assertUnprocessable();
        }
        $this->actingAs($user)->deleteJson($url)->assertAccepted();
        $this->assertSame(0, $domain->securityRules()->count());
    }

    public function test_editing_a_preset_rule_by_hand_makes_it_an_ordinary_rule(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/security/presets/user-agent", ['values' => ['sqlmap'], 'action' => 'block'])->assertAccepted();
        $rule = $domain->securityRules()->firstOrFail();
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/security/rules/{$rule->id}", ['conditions' => [['field' => 'user_agent', 'operator' => 'contains', 'value' => 'nikto']], 'action' => 'block', 'priority' => 5])->assertAccepted();
        $this->assertNull($rule->refresh()->preset);
        $this->assertNull(SecurityPresets::current($domain, SecurityPresets::USER_AGENT));
    }
}
