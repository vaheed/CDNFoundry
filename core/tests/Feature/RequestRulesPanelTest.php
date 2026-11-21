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

    public function test_switching_tabs_rebuilds_the_create_action_and_preset_menu(): void
    {
        Queue::fake();
        [, $domain] = $this->signedIn();
        $this->manager($domain)->assertSee('Add redirect')
            ->set('activeTab', 'rewrite')->assertSee('Add rewrite')->assertDontSee('Add redirect')
            ->callTableAction('create', data: ['name' => 'Switched tab', 'priority' => 10, 'enabled' => true,
                'conditions' => [], 'action' => ['path' => 'prefix', 'from_prefix' => '/api', 'to_prefix' => '', 'query' => 'keep']])
            ->assertHasNoTableActionErrors();
        $this->assertSame('rewrite', $domain->requestRules()->sole()->type);
    }

    public function test_rewrite_header_and_cache_tabs_add_rules_with_the_api_validation(): void
    {
        Queue::fake();
        [, $domain] = $this->signedIn();

        $this->manager($domain, 'rewrite')->assertSee('No URL rewrites')->callTableAction('create', data: [
            'priority' => 10, 'enabled' => true, 'conditions' => [],
            'action' => ['path' => 'prefix', 'from_prefix' => '/api', 'to_prefix' => '', 'query' => 'drop'],
        ])->assertHasNoTableActionErrors()->assertSee('Path starts with /api')->assertSee('Prefix /api to nothing, remove the query string');
        $this->manager($domain, 'rewrite')->callTableAction('create', data: [
            'priority' => 20, 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/x']],
            'action' => ['path' => 'set', 'path_value' => '/../etc', 'query' => 'keep'],
        ])->assertHasTableActionErrors();

        $this->manager($domain, 'header')->callTableAction('create', data: [
            'priority' => 10, 'enabled' => true, 'conditions' => [],
            'action' => ['response' => [['op' => 'set', 'name' => 'X-Frame-Options', 'value' => 'DENY'], ['op' => 'remove', 'name' => 'Server']], 'request' => []],
        ])->assertHasNoTableActionErrors()->assertSee('All requests')->assertSee('Response: set x-frame-options, remove server');
        $this->manager($domain, 'header')->callTableAction('create', data: [
            'priority' => 20, 'conditions' => [], 'action' => ['response' => [['op' => 'set', 'name' => 'X-Forwarded-For', 'value' => '1.1.1.1']], 'request' => []],
        ])->assertHasTableActionErrors();

        $this->manager($domain, 'cache')->callTableAction('create', data: [
            'priority' => 10, 'enabled' => true, 'conditions' => [['field' => 'file_extension', 'operator' => 'in_list', 'value_list' => ['png']]],
            'action' => ['mode' => 'override', 'edge_ttl_seconds' => '86400', 'browser_ttl_seconds' => '', 'respect_origin' => '0'],
        ])->assertHasNoTableActionErrors()->assertSee('Edge TTL 1 d, ignore origin cache headers');
        $this->manager($domain, 'cache')->callTableAction('create', data: [
            'priority' => 20, 'conditions' => [['field' => 'path', 'operator' => 'starts_with', 'value' => '/y']],
            'action' => ['mode' => 'override', 'edge_ttl_seconds' => '', 'browser_ttl_seconds' => '', 'respect_origin' => null],
        ])->assertHasTableActionErrors();

        $this->assertSame(['cache' => 1, 'header' => 1, 'rewrite' => 1], $domain->requestRules()->get()->countBy('type')->sortKeys()->all());
        // Each tab lists only its own type.
        $this->manager($domain, 'cache')->assertSee('Edge TTL 1 d')->assertDontSee('Response: set x-frame-options');
    }

    public function test_presets_of_every_type_save_from_their_tab(): void
    {
        Queue::fake();
        [, $domain] = $this->signedIn();

        $this->manager($domain, 'redirect')->callTableAction('preset_'.RequestRulePresets::HTTP_TO_HTTPS, data: ['status_code' => '301'])->assertHasNoTableActionErrors()
            ->assertSee('301 to the same address over HTTPS, keeping the query string');
        $this->manager($domain, 'rewrite')->callTableAction('preset_'.RequestRulePresets::STRIP_PREFIX, data: ['prefix' => '/api'])->assertHasNoTableActionErrors();
        $this->manager($domain, 'rewrite')->callTableAction('preset_'.RequestRulePresets::ADD_PREFIX, data: ['prefix' => 'app'])->assertHasTableActionErrors(['prefix']);
        $this->manager($domain, 'header')->callTableAction('preset_'.RequestRulePresets::SECURITY_HEADERS, data: [
            'hsts_max_age' => '300', 'hsts_subdomains' => false, 'frame_options' => 'SAMEORIGIN', 'referrer_policy' => 'strict-origin-when-cross-origin',
        ])->assertHasNoTableActionErrors()->assertSee('Security headers');
        $this->manager($domain, 'cache')->callTableAction('preset_'.RequestRulePresets::CACHE_STATIC, data: ['extensions' => ['png', 'css'], 'edge_ttl_days' => '30', 'browser_ttl_days' => '7'])
            ->assertHasNoTableActionErrors()->assertSee('Edge TTL 30 d, browser TTL 7 d');
        $this->manager($domain, 'cache')->callTableAction('preset_'.RequestRulePresets::BYPASS_COOKIES, data: ['cookies' => ['session', 'cart']])->assertHasNoTableActionErrors();

        $this->assertSame([
            RequestRulePresets::BYPASS_COOKIES => 2, RequestRulePresets::CACHE_STATIC => 1, RequestRulePresets::HTTP_TO_HTTPS => 1,
            RequestRulePresets::SECURITY_HEADERS => 1, RequestRulePresets::STRIP_PREFIX => 1,
        ], $domain->requestRules()->get()->countBy('preset')->sortKeys()->all());
        $this->manager($domain, 'cache')->callTableAction('remove_'.RequestRulePresets::BYPASS_COOKIES);
        $this->assertSame(0, $domain->requestRules()->where('preset', RequestRulePresets::BYPASS_COOKIES)->count());
    }

    public function test_the_domain_page_names_the_security_tab_firewall_rules(): void
    {
        [, $domain] = $this->signedIn();
        $this->get("/app/domains/{$domain->id}")->assertOk()->assertSee('Firewall rules')->assertDontSee('Custom rules');
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

    private function manager(Domain $domain, string $type = 'redirect')
    {
        return Livewire::test(RequestRulesRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class, 'activeTab' => $type]);
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
