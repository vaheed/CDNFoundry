<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\SecurityEventsRelationManager;
use App\Models\Domain;
use App\Models\PlatformRuleset;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityEventsPanelTest extends TestCase
{
    use RefreshDatabase;

    private function eventsDomain(): array
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'events-ui.example', 'display_name' => 'Events', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $this->actingAs($user);

        return [$user, $domain];
    }

    private function event(string $rule, string $path, string $reason = 'custom_rule_block', int $score = 5): array
    {
        return ['occurred_at' => now('UTC')->subMinutes(5)->format('Y-m-d H:i:s.v'), 'event_id' => (string) Str::uuid(), 'hostname' => 'events-ui.example', 'method' => 'GET', 'path' => $path, 'status' => 403,
            'security_action' => 'block', 'security_reason' => $reason, 'security_rule' => $rule, 'attack_score' => $score, 'country' => 'DE', 'asn' => 64500, 'user_agent' => 'sqlmap/1.7', 'request_id' => 'req-'.Str::random(6), 'client_ip' => '203.0.113.77'];
    }

    private function fakeEvents(array $events): void
    {
        Http::fake([config('services.clickhouse.url').'*' => Http::response(collect($events)->map(fn ($event) => json_encode($event))->implode("\n"))]);
    }

    private function manager(Domain $domain)
    {
        return Livewire::test(SecurityEventsRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class]);
    }

    public function test_events_show_their_rule_and_a_rule_can_be_built_from_one(): void
    {
        [, $domain] = $this->eventsDomain();
        $own = $domain->securityRules()->create(['name' => 'Block admin probes', 'action' => 'block', 'priority' => 50, 'conditions' => [['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin']], 'fingerprint' => 'a']);
        $event = $this->event("r{$own->id}", '/admin/login');
        $this->fakeEvents([$event, $this->event('waf', '/?q=1', 'waf_block')]);

        $component = $this->manager($domain)->assertSee('Block admin probes')->assertSee('Managed WAF')->assertSee('/admin/login');
        $component->mountTableAction('createRule', $event['event_id'])
            ->assertTableActionDataSet(function (array $data): bool {
                $conditions = array_values($data['conditions']);

                return $data['action'] === 'block' && $conditions[0]['field'] === 'path' && $conditions[0]['value'] === '/admin/login'
                    && collect($conditions)->pluck('field')->all() === ['path', 'country', 'asn', 'user_agent'] && $conditions[1]['value'] === 'DE' && $conditions[2]['value'] === 64500;
            })
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $created = $domain->securityRules()->where('id', '!=', $own->id)->firstOrFail();
        $this->assertSame(['path', 'country', 'asn', 'user_agent'], collect($created->conditions)->pluck('field')->all());
        $this->assertSame('block', $created->action);
    }

    public function test_an_exception_runs_before_the_rule_that_fired_and_skips_the_managed_firewall_for_its_events(): void
    {
        [, $domain] = $this->eventsDomain();
        $own = $domain->securityRules()->create(['name' => 'Block admin probes', 'action' => 'block', 'priority' => 50, 'conditions' => [['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin']], 'fingerprint' => 'a']);
        $ruleset = PlatformRuleset::query()->create(['name' => 'Provider rules', 'applies_to_all' => true]);
        $platform = $ruleset->rules()->create(['action' => 'block', 'priority' => 1, 'conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/p']], 'fingerprint' => 'p']);
        [$domainEvent, $wafEvent, $platformEvent] = [$this->event("r{$own->id}", '/admin/login'), $this->event('waf', '/search', 'waf_block'), $this->event("p{$platform->id}", '/p', 'platform_rule_block')];
        $this->fakeEvents([$domainEvent, $wafEvent, $platformEvent]);

        $this->manager($domain)->callTableAction('createException', $domainEvent['event_id'])->assertHasNoTableActionErrors();
        $exception = $domain->securityRules()->where('action', 'allow')->firstOrFail();
        $this->assertSame(49, $exception->priority);
        $this->assertSame([['field' => 'path', 'operator' => 'equals', 'value' => '/admin/login']], $exception->conditions);

        $this->manager($domain)->callTableAction('createException', $wafEvent['event_id'])->assertHasNoTableActionErrors();
        $this->assertSame('skip_managed', $domain->securityRules()->where('action', 'skip_managed')->firstOrFail()->action);

        // A platform rule runs before the domain's rules, so no exception is offered for it.
        $this->manager($domain)->assertTableActionHidden('createException', $platformEvent['event_id'])->assertSee('Platform ruleset: Provider rules');
    }

    public function test_filters_reach_the_query_and_an_unavailable_store_is_explained(): void
    {
        [, $domain] = $this->eventsDomain();
        $this->fakeEvents([$this->event('waf', '/a', 'waf_block')]);
        $this->manager($domain)->set('tableFilters.events.country', 'DE')->set('tableFilters.events.score_max', 20)->set('tableFilters.events.action', 'blocked');
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'param_score_max=20') && str_contains(urldecode($request->url()), 'param_country=DE'));

    }

    public function test_an_unavailable_store_is_explained_and_an_empty_list_says_why(): void
    {
        [, $domain] = $this->eventsDomain();
        Http::fake([config('services.clickhouse.url').'*' => Http::response('down', 503)]);
        $this->manager($domain)->assertSee('Security events are unavailable')->assertSee('Serving is not affected');
    }

    public function test_an_empty_list_says_what_appears_here(): void
    {
        [, $domain] = $this->eventsDomain();
        $this->fakeEvents([]);
        $this->manager($domain)->assertSee('No mitigated requests')->assertSee('appear here for 7 days');
    }
}
