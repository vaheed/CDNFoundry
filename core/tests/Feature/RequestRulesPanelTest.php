<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\RequestRulesRelationManager;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\RequestRulePresets;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RequestRulesPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_adds_edits_reorders_and_removes_redirects(): void
    {
        Queue::fake();
        [, $domain] = $this->signedIn();

        $this->manager($domain)->callTableAction('create', data: [
            'name' => 'Old blog', 'priority' => 10, 'enabled' => true, 'conditions' => [],
            'action' => ['target' => 'prefix', 'from_prefix' => '/blog', 'to_prefix' => '/news', 'status_code' => '301', 'preserve_query' => true],
        ])->assertHasNoTableActionErrors()->assertSee('Old blog')->assertSee('301 from /blog to /news, keeping the query string');
        $this->manager($domain)->callTableAction('create', data: [
            'priority' => 20, 'enabled' => true, 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/promo']],
            'action' => ['target' => 'url', 'url' => 'https://shop.example/sale', 'status_code' => '302', 'preserve_query' => false],
        ])->assertHasNoTableActionErrors()->assertSee('Path equals /promo');
        $this->assertSame(3, $domain->refresh()->revision);
        Queue::assertPushed(ReconcileEdgeDomain::class);

        // The same validation as the API: a target that loops back to the matched address is refused.
        $this->manager($domain)->callTableAction('create', data: [
            'priority' => 30, 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/same']],
            'action' => ['target' => 'url', 'url' => '/same', 'status_code' => '301', 'preserve_query' => false],
        ])->assertHasTableActionErrors();
        $this->assertSame(2, $domain->requestRules()->count());

        [$first, $second] = $domain->requestRules()->orderBy('priority')->get()->all();
        $this->manager($domain)->callTableAction('moveDown', $first)->assertHasNoTableActionErrors();
        $this->assertSame([$second->id, $first->id], $domain->requestRules()->orderBy('priority')->pluck('id')->all());

        $this->manager($domain)->callTableAction('edit', $second, data: [
            'name' => 'Sale', 'priority' => 5, 'enabled' => false, 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/promo']],
            'action' => ['target' => 'url', 'url' => 'https://shop.example/sale', 'status_code' => '307', 'preserve_query' => true],
        ])->assertHasNoTableActionErrors();
        $this->assertSame(307, $second->refresh()->action['status_code']);
        $this->assertFalse($second->enabled);

        $this->manager($domain)->callTableAction('delete', $first);
        $this->assertSame([$second->id], $domain->requestRules()->pluck('id')->all());
    }

    public function test_presets_save_replace_and_remove_their_rules(): void
    {
        Queue::fake();
        [, $domain] = $this->signedIn();

        $this->manager($domain)->callTableAction('preset_'.RequestRulePresets::WWW_TO_APEX, data: ['status_code' => '308'])->assertHasNoTableActionErrors();
        $this->assertSame('rules-panel.example', $domain->requestRules()->sole()->action['host']);
        $this->manager($domain)->callTableAction('preset_'.RequestRulePresets::MOVE_PATHS, data: ['status_code' => '301', 'moves' => [['from' => '/a', 'to' => '/b'], ['from' => '/c', 'to' => '/d']]])
            ->assertHasNoTableActionErrors();
        $this->assertSame(3, $domain->requestRules()->count());
        $this->manager($domain)->callTableAction('preset_'.RequestRulePresets::MOVE_PATHS, data: ['status_code' => '301', 'moves' => [['from' => '/a', 'to' => '/e']]])
            ->assertHasNoTableActionErrors();
        $this->assertSame(['/e'], $domain->requestRules()->where('preset', RequestRulePresets::MOVE_PATHS)->get()->map(fn ($rule) => $rule->action['to_prefix'])->all());

        $this->manager($domain)->callTableAction('remove_'.RequestRulePresets::MOVE_PATHS);
        $this->manager($domain)->callTableAction('remove_'.RequestRulePresets::WWW_TO_APEX);
        $this->assertSame(0, $domain->requestRules()->count());
    }

    public function test_the_tab_is_scoped_to_the_assigned_domain_and_hidden_until_verification(): void
    {
        [$user, $domain] = $this->signedIn();
        $this->assertContains(RequestRulesRelationManager::class, array_values(Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])->instance()->getRelationManagers()));
        $other = Domain::query()->create(['name' => 'other-panel.example', 'display_name' => 'Other', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $this->actingAs($user)->get("/app/domains/{$other->id}")->assertNotFound();
        $unverified = Domain::query()->create(['name' => 'pending-panel.example', 'display_name' => 'Pending', 'revision' => 1, 'lifecycle_state' => 'pending_verification']);
        $unverified->users()->attach($user->id);
        $this->assertNotContains(RequestRulesRelationManager::class, array_values(Livewire::test(ViewDomain::class, ['record' => $unverified->getRouteKey()])->instance()->getRelationManagers()));
    }

    private function manager(Domain $domain)
    {
        return Livewire::test(RequestRulesRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class]);
    }

    private function signedIn(): array
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'rules-panel.example', 'display_name' => 'Rules', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user->id);
        $this->actingAs($user);

        return [$user, $domain];
    }
}
