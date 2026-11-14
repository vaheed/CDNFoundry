<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\PlatformRulesets\Pages\CreatePlatformRuleset;
use App\Filament\Admin\Resources\PlatformRulesets\Pages\EditPlatformRuleset;
use App\Filament\Admin\Resources\PlatformRulesets\Pages\ListPlatformRulesets;
use App\Filament\Admin\Resources\PlatformRulesets\RelationManagers\RulesRelationManager;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\SecurityRulesRelationManager;
use App\Models\Domain;
use App\Models\PlatformRuleset;
use App\Models\User;
use App\Support\SecurityConfig;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformRulesetsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_creates_a_ruleset_adds_rules_and_deletes_it(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());
        $domain = Domain::query()->create(['name' => 'platform-ui.example', 'display_name' => 'UI', 'revision' => 1, 'lifecycle_state' => 'active']);

        Livewire::test(CreatePlatformRuleset::class)
            ->fillForm(['name' => 'Known scanner paths', 'description' => 'Probes for admin pages', 'enabled' => true, 'applies_to_all' => false, 'domain_ids' => [$domain->id]])
            ->call('create')->assertHasNoFormErrors();
        $ruleset = PlatformRuleset::query()->firstOrFail();
        $this->assertSame([$domain->id], $ruleset->domains()->pluck('domains.id')->all());

        Livewire::test(RulesRelationManager::class, ['ownerRecord' => $ruleset, 'pageClass' => EditPlatformRuleset::class])
            ->callTableAction('create', data: ['action' => 'block', 'priority' => 10, 'enabled' => true, 'name' => 'Login probes', 'conditions' => [['field' => 'path', 'operator' => 'starts_with', 'value' => '/wp-']]])
            ->assertHasNoTableActionErrors()->assertSee('Login probes');
        $rule = $ruleset->rules()->firstOrFail();
        Livewire::test(RulesRelationManager::class, ['ownerRecord' => $ruleset, 'pageClass' => EditPlatformRuleset::class])
            ->callTableAction('create', data: ['action' => 'block', 'priority' => 20, 'enabled' => true, 'conditions' => [['field' => 'path', 'operator' => 'starts_with', 'value' => '/wp-']]])
            ->assertHasTableActionErrors();
        $this->assertSame(1, $ruleset->rules()->count());
        $this->assertNotEmpty(SecurityConfig::compile($domain)['platform_rules']);

        // Switching on "all domains" clears the chosen list; the edit form opens with the saved domains.
        Livewire::test(EditPlatformRuleset::class, ['record' => $ruleset->id])->assertSet('data.domain_ids', [$domain->id])
            ->fillForm(['applies_to_all' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($ruleset->refresh()->applies_to_all);
        $this->assertSame(0, $ruleset->domains()->count());

        Livewire::test(RulesRelationManager::class, ['ownerRecord' => $ruleset, 'pageClass' => EditPlatformRuleset::class])->callTableAction('delete', $rule);
        $this->assertSame(0, $ruleset->rules()->count());
        Livewire::test(ListPlatformRulesets::class)->assertSee('Known scanner paths')->callTableAction('delete', $ruleset);
        $this->assertSame(0, PlatformRuleset::query()->count());
        $this->assertSame([], SecurityConfig::compile($domain)['platform_rules']);
    }

    public function test_only_administrators_reach_the_screen_and_owners_see_names_not_rules(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'owner-ui.example', 'display_name' => 'Owner', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($owner->id);
        $ruleset = PlatformRuleset::query()->create(['name' => 'Provider rules', 'applies_to_all' => true]);
        $ruleset->rules()->create(['action' => 'block', 'priority' => 1, 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/very-secret-path']], 'fingerprint' => 'x']);

        $this->actingAs($owner)->get('/admin/platform-rulesets')->assertForbidden();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($owner);
        Livewire::test(SecurityRulesRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])
            ->assertSee('Applied first by your provider: Provider rules.')->assertDontSee('very-secret-path');
    }
}
