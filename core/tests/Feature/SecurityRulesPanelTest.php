<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\SecurityRulesRelationManager;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\SecurityRuleData;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityRulesPanelTest extends TestCase
{
    use RefreshDatabase;

    private function manager(Domain $domain)
    {
        return Livewire::test(SecurityRulesRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class]);
    }

    public function test_a_user_adds_edits_reorders_and_removes_typed_rules(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'rules-panel.example', 'display_name' => 'Rules', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $this->actingAs($user);

        $this->manager($domain)->callTableAction('create', data: [
            'name' => 'Admin from abroad', 'action' => 'block', 'priority' => 10, 'enabled' => true,
            'conditions' => [
                ['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin'],
                ['field' => 'country', 'operator' => 'in_list', 'value' => ['IR', 'RU']],
                ['field' => 'cookie_present', 'operator' => 'equals', 'name' => 'session', 'value' => false],
            ],
        ])->assertHasNoTableActionErrors()->assertSee('Admin from abroad');
        $rule = $domain->securityRules()->firstOrFail();
        $this->assertSame(['IR', 'RU'], $rule->conditions[1]['value']);
        $this->assertSame('Path starts with /admin and Country is one of IR, RU and Cookie session is absent', SecurityRuleData::describe($rule->conditions));
        $this->assertSame(2, $domain->refresh()->revision);
        Queue::assertPushed(ReconcileEdgeDomain::class);

        $this->manager($domain)->callTableAction('create', data: [
            'action' => 'log', 'priority' => 20, 'enabled' => true,
            'conditions' => [['field' => 'asn', 'operator' => 'greater_than', 'value' => '64500']],
        ])->assertHasNoTableActionErrors();
        $second = $domain->securityRules()->where('action', 'log')->firstOrFail();
        $this->assertSame(64500, $second->conditions[0]['value']);

        // A rule with the same conditions and action is refused, and an unsupported operator is reported on its field.
        $this->manager($domain)->callTableAction('create', data: [
            'name' => 'Same', 'action' => 'block', 'priority' => 30, 'enabled' => true, 'conditions' => $rule->conditions,
        ]);
        $this->assertSame(2, $domain->securityRules()->count());
        $this->manager($domain)->callTableAction('create', data: [
            'action' => 'block', 'priority' => 30, 'enabled' => true,
            'conditions' => [['field' => 'path', 'operator' => 'greater_than', 'value' => '/x']],
        ]);
        $this->assertSame(2, $domain->securityRules()->count());

        $this->manager($domain)->callTableAction('moveDown', $rule)->assertHasNoTableActionErrors();
        $this->assertSame([$second->id, $rule->id], $domain->securityRules()->orderBy('priority')->pluck('id')->all());

        $this->manager($domain)->callTableAction('edit', $second, data: [
            'name' => 'Watch large bodies', 'action' => 'log', 'priority' => 5, 'enabled' => false,
            'conditions' => [['field' => 'body_size', 'operator' => 'greater_than', 'value' => 1000]],
        ])->assertHasNoTableActionErrors();
        $this->assertFalse($second->refresh()->enabled);

        $this->manager($domain)->callTableAction('delete', $rule);
        $this->assertSame(1, $domain->securityRules()->count());
    }

    public function test_a_user_adds_edits_and_clears_a_rate_limit(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'rate-panel.example', 'display_name' => 'Rate', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $this->actingAs($user);

        $this->manager($domain)->callTableAction('create', data: [
            'name' => 'Login attempts', 'action' => 'block', 'priority' => 10, 'enabled' => true, 'rate_limited' => true,
            'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/login']],
            'rate_limit' => ['key' => 'header', 'header_name' => 'X-Api-Key', 'threshold' => 20, 'period_seconds' => 60, 'duration_seconds' => 300],
        ])->assertHasNoTableActionErrors();
        $rule = $domain->securityRules()->firstOrFail();
        $this->assertSame(['key' => 'header', 'threshold' => 20, 'period_seconds' => 60, 'duration_seconds' => 300, 'header_name' => 'x-api-key'], $rule->rate_limit);
        $this->assertSame('20 requests per 1 minute per header value x-api-key, then block for 5 min', SecurityRuleData::describeRateLimit($rule->rate_limit, 'block'));

        // An allow action is not offered for a rate limit, and switching the limit off clears it.
        $this->manager($domain)->callTableAction('edit', $rule, data: [
            'name' => 'Login attempts', 'action' => 'allow', 'priority' => 10, 'enabled' => true, 'rate_limited' => true,
            'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/login']],
            'rate_limit' => ['key' => 'client_ip', 'threshold' => 20, 'period_seconds' => 60, 'duration_seconds' => 300],
        ])->assertHasTableActionErrors(['action']);
        $this->manager($domain)->callTableAction('edit', $rule, data: [
            'name' => 'Login attempts', 'action' => 'block', 'priority' => 10, 'enabled' => true, 'rate_limited' => false,
            'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/login']],
        ])->assertHasNoTableActionErrors();
        $this->assertNull($rule->refresh()->rate_limit);
    }

    public function test_the_presets_menu_saves_and_removes_both_presets(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'presets-panel.example', 'display_name' => 'Presets', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $this->actingAs($user);

        $this->manager($domain)->callTableAction('userAgentPreset', data: ['values' => ['sqlmap', 'nikto'], 'action' => 'block'])->assertHasNoTableActionErrors();
        $this->assertSame(2, $domain->securityRules()->where('preset', 'user_agent')->count());
        $this->manager($domain)->callTableAction('hotlinkPreset', data: ['extensions' => ['jpg'], 'allowed_hosts' => ['partner.example']])->assertHasNoTableActionErrors();
        $this->assertSame(1, $domain->securityRules()->where('preset', 'hotlink')->count());
        $this->manager($domain)->assertTableActionVisible('removeUserAgentPreset')->assertTableActionVisible('removeHotlinkPreset')->assertSee('User agent: sqlmap')->assertSee('Hotlink protection');

        $this->manager($domain)->callTableAction('userAgentPreset', data: ['values' => [], 'action' => 'block'])->assertHasTableActionErrors(['values']);
        $this->manager($domain)->callTableAction('removeUserAgentPreset');
        $this->manager($domain)->callTableAction('removeHotlinkPreset');
        $this->assertSame(0, $domain->securityRules()->count());
        $this->manager($domain)->assertTableActionHidden('removeUserAgentPreset');
    }
}
