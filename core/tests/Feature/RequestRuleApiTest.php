<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Models\User;
use App\Support\DomainRequestRules;
use App\Support\RequestRuleData;
use App\Support\RequestRulePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RequestRuleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_type_uses_the_same_tenant_policy_and_idempotency_boundary(): void
    {
        Queue::fake();
        [$owner, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();
        $admin = User::factory()->create(['type' => 'admin']);
        $url = "/api/domains/{$domain->id}/request-rules";
        foreach ([
            $this->redirect(),
            $this->rule('rewrite', ['path' => 'set', 'path_value' => '/index.php', 'query' => 'keep']),
            $this->rule('header', ['request' => [], 'response' => [['op' => 'remove', 'name' => 'server']]]),
            $this->rule('cache', ['mode' => 'bypass']),
        ] as $index => $input) {
            $this->actingAs($stranger)->postJson($url, $input)->assertNotFound();
            $headers = ['Idempotency-Key' => sprintf('00000000-0000-4000-8000-%012d', $index)];
            $created = $this->actingAs($owner)->postJson($url, $input, $headers)->assertAccepted();
            $id = $created->json('data.id');
            $this->actingAs($owner)->postJson($url, $input, $headers)->assertAccepted()->assertJsonPath('data.id', $id);
            $this->actingAs($owner)->postJson($url, [...$input, 'name' => 'Different'], $headers)->assertConflict();
            $this->actingAs($stranger)->patchJson("$url/$id", $input)->assertNotFound();
            $this->actingAs($stranger)->deleteJson("$url/$id")->assertNotFound();
            $this->actingAs($admin)->patchJson("$url/$id", [...$input, 'name' => 'Reviewed'])->assertAccepted();
            $this->actingAs($owner)->deleteJson("$url/$id")->assertAccepted();
        }
        $this->assertSame(0, $domain->requestRules()->count());
    }

    public function test_an_operation_failure_rolls_back_the_rule_revision_and_audit(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        Operation::creating(function (): void {
            throw new \RuntimeException('operation write failed');
        });
        try {
            DomainRequestRules::create($domain, $this->redirect(), $user, '127.0.0.1');
            $this->fail('The operation failure must abort the mutation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('operation write failed', $exception->getMessage());
        }
        $this->assertSame(1, $domain->refresh()->revision);
        $this->assertSame(0, $domain->requestRules()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'request_rule.created']);
        Queue::assertNothingPushed();
    }

    public function test_a_redirect_rule_is_authorized_revisioned_audited_and_queued(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();
        $admin = User::factory()->create(['type' => 'admin']);

        $this->actingAs($stranger)->postJson("/api/domains/{$domain->id}/request-rules", $this->redirect())->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/request-rules")->assertNotFound();

        $created = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/request-rules", $this->redirect())
            ->assertAccepted()->assertJsonPath('data.type', 'redirect')->assertJsonPath('data.action.status_code', 301)
            ->assertJsonStructure(['data' => ['id', 'operation_id', 'status']]);
        $created->assertJsonMissingPath('data.fingerprint')->assertJsonMissingPath('data.domain_id');
        $this->assertSame(2, $domain->refresh()->revision);
        Queue::assertPushed(ReconcileEdgeDomain::class, fn ($job): bool => $job->domainId === $domain->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request_rule.created', 'subject_id' => (string) $created->json('data.id')]);

        $this->actingAs($admin)->getJson("/api/domains/{$domain->id}/request-rules?type=redirect")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/request-rules?type=nonsense")->assertUnprocessable();
    }

    public function test_every_target_is_validated_and_stored_in_its_typed_shape(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules";

        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'host', 'host' => 'Example.Test', 'scheme' => 'https', 'status_code' => 308, 'preserve_query' => true],
            [['field' => 'hostname', 'operator' => 'equals', 'value' => 'www.example.test']]))->assertAccepted()->assertJsonPath('data.action.host', 'example.test');
        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'prefix', 'from_prefix' => '/blog', 'to_prefix' => '/news', 'status_code' => 302, 'preserve_query' => false], []))
            ->assertAccepted()->assertJsonPath('data.conditions', []);
        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'url', 'url' => 'https://shop.example/sale?from=cdn', 'status_code' => 307, 'preserve_query' => true],
            [['field' => 'client_ip', 'operator' => 'equals', 'value' => '2001:db8:0:0::/48']]))
            ->assertAccepted()->assertJsonPath('data.conditions.0.value', '2001:db8::/48');

        $invalid = [
            'action.status_code' => $this->redirect(['target' => 'url', 'url' => '/new', 'status_code' => 303, 'preserve_query' => false]),
            'action.target' => $this->redirect(['target' => 'regex', 'status_code' => 301, 'preserve_query' => false]),
            // Only http and https addresses with a host and no user information, or a path on the same host.
            'action.url' => $this->redirect(['target' => 'url', 'url' => 'javascript:alert(1)', 'status_code' => 301, 'preserve_query' => false]),
        ];
        foreach ([
            'https://user:secret@example.test/', '//evil.example/x', 'https://exa mple.test/', "/a\r\nSet-Cookie: x=1", 'ftp://example.test/', str_repeat('/a', 1100),
        ] as $index => $target) {
            $invalid["action.url#$index"] = $this->redirect(['target' => 'url', 'url' => $target, 'status_code' => 301, 'preserve_query' => false]);
        }
        $invalid['action.host'] = $this->redirect(['target' => 'host', 'host' => 'not a host', 'status_code' => 301, 'preserve_query' => false]);
        $invalid['action.from_prefix'] = $this->redirect(['target' => 'prefix', 'from_prefix' => 'blog', 'to_prefix' => '/news', 'status_code' => 301, 'preserve_query' => false], []);
        // Score and detection fields are not offered: a request rule never makes a request pay for scoring.
        $invalid['conditions.0.field'] = $this->redirect(null, [['field' => 'attack_score', 'operator' => 'less_than', 'value' => 20]]);
        $invalid['conditions'] = $this->redirect(null, []);
        foreach ($invalid as $key => $body) {
            $this->actingAs($user)->postJson($url, $body)->assertUnprocessable()->assertJsonValidationErrors(explode('#', $key)[0]);
        }
        $this->assertSame(3, $domain->requestRules()->count());
    }

    public function test_a_rule_that_would_redirect_to_itself_is_refused(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules";
        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'url', 'url' => '/same', 'status_code' => 301, 'preserve_query' => false],
            [['field' => 'path', 'operator' => 'equals', 'value' => '/same']]))->assertUnprocessable()->assertJsonValidationErrors('action.url');
        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'host', 'host' => 'www.example.test', 'status_code' => 301, 'preserve_query' => false],
            [['field' => 'hostname', 'operator' => 'equals', 'value' => 'WWW.example.test']]))->assertUnprocessable()->assertJsonValidationErrors('action.host');
        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'prefix', 'from_prefix' => '/old', 'to_prefix' => '/old-site', 'status_code' => 301, 'preserve_query' => false], []))
            ->assertUnprocessable()->assertJsonValidationErrors('action.to_prefix');
        $this->assertSame(0, $domain->requestRules()->count());
    }

    public function test_duplicates_and_the_per_type_limit_are_refused(): void
    {
        Queue::fake();
        config(['request_rules.maximum_rules_per_type' => 2]);
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules";
        $this->actingAs($user)->postJson($url, $this->redirect())->assertAccepted();
        $this->actingAs($user)->postJson($url, [...$this->redirect(), 'name' => 'Same rule'])->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $this->actingAs($user)->postJson($url, $this->redirect(null, [['field' => 'path', 'operator' => 'equals', 'value' => '/two']]))->assertAccepted();
        $this->actingAs($user)->postJson($url, $this->redirect(null, [['field' => 'path', 'operator' => 'equals', 'value' => '/three']]))->assertConflict();
        $this->assertSame(2, $domain->requestRules()->count());
    }

    public function test_a_replayed_creation_creates_one_rule_and_a_changed_replay_conflicts(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $headers = ['Idempotency-Key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
        $url = "/api/domains/{$domain->id}/request-rules";
        $first = $this->actingAs($user)->withHeaders($headers)->postJson($url, $this->redirect())->assertAccepted();
        $this->actingAs($user)->withHeaders($headers)->postJson($url, $this->redirect())->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        $this->actingAs($user)->withHeaders($headers)->postJson($url, $this->redirect(null, [['field' => 'path', 'operator' => 'equals', 'value' => '/other']]))->assertConflict();
        $this->assertSame(1, $domain->requestRules()->count());
    }

    public function test_update_delete_and_reorder_change_one_type_and_raise_the_revision(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $other = Domain::query()->create(['name' => 'other.example.test', 'display_name' => 'Other', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $url = "/api/domains/{$domain->id}/request-rules";
        $first = $this->actingAs($user)->postJson($url, $this->redirect())->json('data.id');
        $second = $this->actingAs($user)->postJson($url, $this->redirect(null, [['field' => 'path', 'operator' => 'equals', 'value' => '/two']]))->json('data.id');

        $this->actingAs($user)->patchJson("$url/$first", [...$this->redirect(['target' => 'url', 'url' => '/moved', 'status_code' => 302, 'preserve_query' => true]), 'enabled' => false])
            ->assertAccepted()->assertJsonStructure(['data' => ['operation_id', 'status']]);
        $this->assertSame('/moved', $domain->requestRules()->find($first)->action['url']);
        $this->assertFalse($domain->requestRules()->find($first)->enabled);
        // A rule of another domain is not reachable through this one.
        $foreign = $other->requestRules()->create(RequestRuleData::validate($this->redirect()));
        $this->actingAs($user)->patchJson("$url/{$foreign->id}", $this->redirect())->assertNotFound();
        $this->actingAs($user)->deleteJson("$url/{$foreign->id}")->assertNotFound();

        $this->actingAs($user)->putJson("$url/order", ['type' => 'redirect', 'order' => [$first]])->assertUnprocessable();
        $this->actingAs($user)->putJson("$url/order", ['type' => 'redirect', 'order' => [$second, $first]])->assertAccepted();
        $this->assertSame([$second, $first], $domain->requestRules()->orderBy('priority')->pluck('id')->all());

        $this->actingAs($user)->deleteJson("$url/$second")->assertAccepted();
        $this->assertSame([$first], $domain->requestRules()->pluck('id')->all());
        $this->assertSame(6, $domain->refresh()->revision);
    }

    public function test_presets_write_marked_rules_replace_them_when_saved_again_and_can_be_removed(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules/presets";

        $this->actingAs($user)->putJson("$url/".RequestRulePresets::WWW_TO_APEX, ['status_code' => 301])->assertAccepted();
        $rule = $domain->requestRules()->sole();
        $this->assertSame(['field' => 'hostname', 'operator' => 'equals', 'value' => 'www.example.test'], $rule->conditions[0]);
        $this->assertSame(['target' => 'host', 'status_code' => 301, 'preserve_query' => true, 'host' => 'example.test', 'scheme' => 'same'], $rule->action);
        $this->assertSame(RequestRulePresets::WWW_TO_APEX, $rule->preset);

        $this->actingAs($user)->putJson("$url/".RequestRulePresets::WWW_TO_APEX, ['status_code' => 308])->assertAccepted();
        $this->assertSame(308, $domain->requestRules()->sole()->action['status_code']);

        $this->actingAs($user)->putJson("$url/".RequestRulePresets::MOVE_PATHS, ['status_code' => 301, 'moves' => [['from' => '/blog', 'to' => '/news'], ['from' => '/shop', 'to' => '/store']]])->assertAccepted();
        $this->assertSame(3, $domain->requestRules()->count());
        $this->assertSame(['moves' => [['from' => '/blog', 'to' => '/news'], ['from' => '/shop', 'to' => '/store']], 'status_code' => 301], RequestRulePresets::current($domain, RequestRulePresets::MOVE_PATHS));
        $this->actingAs($user)->putJson("$url/".RequestRulePresets::MOVE_PATHS, ['status_code' => 301, 'moves' => [['from' => '/a', 'to' => '/a/b']]])
            ->assertUnprocessable()->assertJsonValidationErrors('moves.0.to');
        $this->actingAs($user)->putJson("$url/unknown", ['status_code' => 301])->assertNotFound();

        // A preset rule edited by hand is an ordinary rule from then on.
        $edited = $domain->requestRules()->where('preset', RequestRulePresets::WWW_TO_APEX)->sole();
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/request-rules/{$edited->id}", [
            'name' => 'Edited', 'priority' => 1, 'conditions' => $edited->conditions, 'action' => [...$edited->action, 'status_code' => 302],
        ])->assertAccepted();
        $this->assertNull($edited->refresh()->preset);

        $this->actingAs($user)->deleteJson("$url/".RequestRulePresets::MOVE_PATHS)->assertAccepted();
        $this->assertSame([$edited->id], $domain->requestRules()->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'request_rule.preset_removed']);
    }

    public function test_the_snapshot_carries_enabled_redirects_in_order(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain(proxied: true);
        $url = "/api/domains/{$domain->id}/request-rules";
        $this->actingAs($user)->postJson($url, [...$this->redirect(), 'priority' => 20]);
        $this->actingAs($user)->postJson($url, [...$this->redirect(null, [['field' => 'path', 'operator' => 'equals', 'value' => '/first']]), 'priority' => 10]);
        $this->actingAs($user)->postJson($url, [...$this->redirect(null, [['field' => 'path', 'operator' => 'equals', 'value' => '/off']]), 'enabled' => false]);

        (new ReconcileEdgeDomain($domain->id))->handle();
        $snapshot = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail()->snapshot;
        $redirects = $snapshot['rules']['redirects'];
        $this->assertSame(['/first', '/old'], array_map(fn (array $rule): string => $rule['conditions'][0]['value'], $redirects));
        $this->assertSame(['id', 'conditions', 'action'], array_keys($redirects[0]));
    }

    public function test_rewrites_are_stored_typed_and_every_bound_is_refused(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules";

        // An empty new prefix removes the old one, and a prefix rewrite needs no condition.
        $this->actingAs($user)->postJson($url, $this->rule('rewrite', ['path' => 'prefix', 'from_prefix' => '/api', 'to_prefix' => '', 'query' => 'keep'], []))
            ->assertAccepted()->assertJsonPath('data.action', ['path' => 'prefix', 'query' => 'keep', 'from_prefix' => '/api', 'to_prefix' => '']);
        $this->actingAs($user)->postJson($url, $this->rule('rewrite', ['path' => 'set', 'path_value' => '/index.php', 'query' => 'set', 'query_value' => '?lang=en&page=1']))
            ->assertAccepted()->assertJsonPath('data.action.query_value', 'lang=en&page=1');
        $this->actingAs($user)->postJson($url, $this->rule('rewrite', ['path' => 'keep', 'query' => 'drop']))->assertAccepted();

        $invalid = [
            'action.path' => $this->rule('rewrite', ['path' => 'keep', 'query' => 'keep']),
            'action.path_value' => $this->rule('rewrite', ['path' => 'set', 'path_value' => '/a/../etc/passwd', 'query' => 'keep']),
            'action.path_value#encoded' => $this->rule('rewrite', ['path' => 'set', 'path_value' => '/a/%2e%2e/b', 'query' => 'keep']),
            'action.path_value#dot' => $this->rule('rewrite', ['path' => 'set', 'path_value' => '/./b', 'query' => 'keep']),
            'action.path_value#relative' => $this->rule('rewrite', ['path' => 'set', 'path_value' => 'index.php', 'query' => 'keep']),
            'action.from_prefix' => $this->rule('rewrite', ['path' => 'prefix', 'from_prefix' => '/api/..', 'to_prefix' => '', 'query' => 'keep'], []),
            'action.to_prefix' => $this->rule('rewrite', ['path' => 'prefix', 'from_prefix' => '/api', 'to_prefix' => '/api', 'query' => 'keep'], []),
            'action.query_value' => $this->rule('rewrite', ['path' => 'keep', 'query' => 'set', 'query_value' => 'a=1 b=2']),
            'action.query_value#newline' => $this->rule('rewrite', ['path' => 'keep', 'query' => 'set', 'query_value' => "a=1\r\nx: y"]),
            'action.query_value#long' => $this->rule('rewrite', ['path' => 'keep', 'query' => 'set', 'query_value' => str_repeat('a', RequestRuleData::MAXIMUM_QUERY_BYTES + 1)]),
            'action.query' => $this->rule('rewrite', ['path' => 'keep', 'query' => 'regex']),
            // Only a prefix rewrite matches on its own prefix; a fixed path needs a condition.
            'conditions' => $this->rule('rewrite', ['path' => 'set', 'path_value' => '/index.php', 'query' => 'keep'], []),
        ];
        foreach ($invalid as $key => $body) {
            $this->actingAs($user)->postJson($url, $body)->assertUnprocessable()->assertJsonValidationErrors(explode('#', $key)[0]);
        }
        $this->assertSame(3, $domain->requestRules()->where('type', 'rewrite')->count());
    }

    public function test_header_rules_refuse_protected_headers_duplicates_and_more_than_ten_changes(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules";
        $set = fn (string $name, string $value = 'on'): array => ['op' => 'set', 'name' => $name, 'value' => $value];

        // A header rule may apply to every request; names are stored in lower case.
        $this->actingAs($user)->postJson($url, $this->rule('header', ['request' => [['op' => 'remove', 'name' => 'Cookie2']], 'response' => [$set('X-Frame-Options', ' DENY ')]], []))
            ->assertAccepted()->assertJsonPath('data.action', ['request' => [['op' => 'remove', 'name' => 'cookie2']], 'response' => [['op' => 'set', 'name' => 'x-frame-options', 'value' => 'DENY']]]);

        foreach ([...RequestRuleData::PROTECTED_HEADERS, 'X-Forwarded-For', 'x-forwarded-proto', 'X-CDN-Cache', 'x-cdnf-request-id'] as $name) {
            $this->actingAs($user)->postJson($url, $this->rule('header', ['request' => [], 'response' => [$set($name)]], []))
                ->assertUnprocessable()->assertJsonValidationErrors('action.response.0.name');
            $this->actingAs($user)->postJson($url, $this->rule('header', ['request' => [['op' => 'remove', 'name' => $name]], 'response' => []], []))
                ->assertUnprocessable()->assertJsonValidationErrors('action.request.0.name');
        }
        $tooMany = fn (int $count): array => array_map(fn (int $index): array => $set("x-test-$index"), range(1, $count));
        $invalid = [
            'action.response.1.name' => ['request' => [], 'response' => [$set('x-a'), $set('X-A')]],
            'action.response.0.name' => ['request' => [], 'response' => [$set('bad header')]],
            'action.response.0.op' => ['request' => [], 'response' => [['op' => 'append', 'name' => 'x-a', 'value' => 'b']]],
            'action.response.0.value' => ['request' => [], 'response' => [$set('x-a', "one\r\nSet-Cookie: x=1")]],
            'action.response.0.value#empty' => ['request' => [], 'response' => [$set('x-a', '  ')]],
            'action.response.0.value#long' => ['request' => [], 'response' => [$set('x-a', str_repeat('v', RequestRuleData::MAXIMUM_HEADER_VALUE_BYTES + 1))]],
            'action.response' => ['request' => [], 'response' => []],
            'action.response#eleven' => ['request' => [], 'response' => $tooMany(11)],
            // The limit counts both directions together.
            'action.response#both' => ['request' => $tooMany(6), 'response' => array_map(fn (int $index): array => $set("x-other-$index"), range(1, 5))],
        ];
        foreach ($invalid as $key => $action) {
            $this->actingAs($user)->postJson($url, $this->rule('header', $action, []))->assertUnprocessable()->assertJsonValidationErrors(explode('#', $key)[0]);
        }
        $this->actingAs($user)->postJson($url, $this->rule('header', ['request' => [], 'response' => $tooMany(RequestRuleData::MAXIMUM_HEADER_OPERATIONS)], []))->assertAccepted();
        $this->assertSame(2, $domain->requestRules()->count());
    }

    public function test_cache_rules_bypass_or_override_and_an_override_needs_a_value(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules";

        // Bypass keeps nothing else, even when lifetimes are sent with it.
        $this->actingAs($user)->postJson($url, $this->rule('cache', ['mode' => 'bypass', 'edge_ttl_seconds' => 60]))->assertAccepted()->assertJsonPath('data.action', ['mode' => 'bypass']);
        $this->actingAs($user)->postJson($url, $this->rule('cache', ['mode' => 'override', 'edge_ttl_seconds' => 86400, 'browser_ttl_seconds' => 0, 'respect_origin' => false],
            [['field' => 'file_extension', 'operator' => 'in_list', 'value' => ['png', 'css']]]))
            ->assertAccepted()->assertJsonPath('data.action', ['mode' => 'override', 'edge_ttl_seconds' => 86400, 'browser_ttl_seconds' => 0, 'respect_origin' => false]);

        $invalid = [
            'action.edge_ttl_seconds' => ['mode' => 'override'],
            'action.edge_ttl_seconds#negative' => ['mode' => 'override', 'edge_ttl_seconds' => -1],
            'action.browser_ttl_seconds' => ['mode' => 'override', 'browser_ttl_seconds' => RequestRuleData::MAXIMUM_TTL_SECONDS + 1],
            'action.respect_origin' => ['mode' => 'override', 'respect_origin' => 'sometimes'],
            'action.mode' => ['mode' => 'purge'],
        ];
        foreach ($invalid as $key => $action) {
            $this->actingAs($user)->postJson($url, $this->rule('cache', $action))->assertUnprocessable()->assertJsonValidationErrors(explode('#', $key)[0]);
        }
        $this->actingAs($user)->postJson($url, $this->rule('cache', ['mode' => 'bypass'], []))->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $this->assertSame(2, $domain->requestRules()->count());
    }

    public function test_every_preset_writes_the_rules_it_describes(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules/presets";
        $preset = function (string $name, array $input) use ($user, $url, $domain): array {
            $this->actingAs($user)->putJson("$url/$name", $input)->assertAccepted();

            return $domain->requestRules()->where('preset', $name)->orderBy('priority')->orderBy('id')->get()->map(fn ($rule): array => [$rule->type, $rule->conditions, $rule->action])->all();
        };

        $this->assertSame([['redirect', [['field' => 'tls_version', 'operator' => 'equals', 'value' => 'none']], ['target' => 'https', 'status_code' => 308, 'preserve_query' => true]]],
            $preset(RequestRulePresets::HTTP_TO_HTTPS, ['status_code' => 308]));
        $this->assertSame('www.example.test', $preset(RequestRulePresets::APEX_TO_WWW, ['status_code' => 301])[0][2]['host']);
        $this->assertSame([['redirect', [['field' => 'hostname', 'operator' => 'in_list', 'value' => ['shop.example.test', 'old.example.test']]],
            ['target' => 'host', 'status_code' => 302, 'preserve_query' => true, 'host' => 'example.test', 'scheme' => 'same']]],
            $preset(RequestRulePresets::MERGE_HOSTS, ['hosts' => ['Shop.example.test', 'old.example.test', 'shop.example.test'], 'target' => 'Example.test', 'status_code' => 302]));
        $this->assertSame(['hosts' => ['shop.example.test', 'old.example.test'], 'target' => 'example.test', 'status_code' => 302], RequestRulePresets::current($domain, RequestRulePresets::MERGE_HOSTS));

        $this->assertSame([['rewrite', [], ['path' => 'prefix', 'query' => 'keep', 'from_prefix' => '/api', 'to_prefix' => '']]], $preset(RequestRulePresets::STRIP_PREFIX, ['prefix' => '/api/']));
        $this->assertSame([['rewrite', [], ['path' => 'prefix', 'query' => 'keep', 'from_prefix' => '/', 'to_prefix' => '/app/']]], $preset(RequestRulePresets::ADD_PREFIX, ['prefix' => '/app']));
        $this->assertSame(['prefix' => '/app'], RequestRulePresets::current($domain, RequestRulePresets::ADD_PREFIX));

        $headers = $preset(RequestRulePresets::SECURITY_HEADERS, ['hsts_max_age' => 86400, 'hsts_subdomains' => true, 'frame_options' => 'DENY', 'referrer_policy' => 'no-referrer']);
        $this->assertSame(['strict-transport-security' => 'max-age=86400; includeSubDomains', 'x-content-type-options' => 'nosniff', 'x-frame-options' => 'DENY',
            'referrer-policy' => 'no-referrer', 'permissions-policy' => 'camera=(), microphone=(), geolocation=()'], collect($headers[0][2]['response'])->pluck('value', 'name')->all());
        $this->assertSame([], $headers[0][1]);
        // No HSTS and no frame header leave those two out, and the form reads them back the same way.
        $headers = $preset(RequestRulePresets::SECURITY_HEADERS, ['hsts_max_age' => 0, 'hsts_subdomains' => false, 'frame_options' => 'none', 'referrer_policy' => 'same-origin']);
        $this->assertSame(['x-content-type-options', 'referrer-policy', 'permissions-policy'], array_column($headers[0][2]['response'], 'name'));
        $this->assertSame(['hsts_max_age' => 0, 'hsts_subdomains' => false, 'frame_options' => 'none', 'referrer_policy' => 'same-origin'], RequestRulePresets::current($domain, RequestRulePresets::SECURITY_HEADERS));
        $this->assertSame(RequestRulePresets::ORIGIN_SOFTWARE_HEADERS, array_column($preset(RequestRulePresets::HIDE_ORIGIN_HEADERS, [])[0][2]['response'], 'name'));
        $this->assertSame([['response' => [['op' => 'set', 'name' => 'access-control-allow-origin', 'value' => 'https://app.example.test']], 'request' => []]],
            array_map(fn (array $rule): array => ['response' => $rule[2]['response'], 'request' => $rule[2]['request']], $preset(RequestRulePresets::CORS_STATIC, ['extensions' => ['.WOFF2', 'css'], 'origin' => 'https://app.example.test'])));

        $this->assertSame([['cache', [['field' => 'file_extension', 'operator' => 'in_list', 'value' => ['png', 'svg']]], ['mode' => 'override', 'edge_ttl_seconds' => 2592000, 'browser_ttl_seconds' => 0]]],
            $preset(RequestRulePresets::CACHE_STATIC, ['extensions' => ['png', '.SVG'], 'edge_ttl_days' => 30, 'browser_ttl_days' => 0]));
        $this->assertSame(['extensions' => ['png', 'svg'], 'edge_ttl_days' => 30, 'browser_ttl_days' => 0], RequestRulePresets::current($domain, RequestRulePresets::CACHE_STATIC));
        $this->assertSame([['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin'], ['field' => 'path', 'operator' => 'starts_with', 'value' => '/login']],
            array_map(fn (array $rule): array => $rule[1][0], $preset(RequestRulePresets::BYPASS_PATHS, ['paths' => ['/admin', '/login', '/admin']])));
        $this->assertSame([['cache', [['field' => 'cookie_present', 'operator' => 'equals', 'name' => 'wordpress_logged_in', 'value' => true]], ['mode' => 'bypass']]],
            $preset(RequestRulePresets::BYPASS_COOKIES, ['cookies' => ['wordpress_logged_in']]));
        $this->assertSame(['cookies' => ['wordpress_logged_in']], RequestRulePresets::current($domain, RequestRulePresets::BYPASS_COOKIES));

        $refused = [
            [RequestRulePresets::HTTP_TO_HTTPS, ['status_code' => 303], 'status_code'],
            [RequestRulePresets::MERGE_HOSTS, ['hosts' => ['a.example.test'], 'target' => 'a.example.test', 'status_code' => 301], 'hosts'],
            [RequestRulePresets::MERGE_HOSTS, ['hosts' => array_map(fn (int $index): string => "h$index.example.test", range(1, 21)), 'target' => 'example.test', 'status_code' => 301], 'hosts'],
            [RequestRulePresets::STRIP_PREFIX, ['prefix' => 'api'], 'prefix'],
            [RequestRulePresets::STRIP_PREFIX, ['prefix' => '/api/../x'], 'prefix'],
            [RequestRulePresets::ADD_PREFIX, ['prefix' => '/'], 'prefix'],
            [RequestRulePresets::SECURITY_HEADERS, ['hsts_max_age' => 99999999, 'hsts_subdomains' => false, 'frame_options' => 'DENY', 'referrer_policy' => 'no-referrer'], 'hsts_max_age'],
            [RequestRulePresets::SECURITY_HEADERS, ['hsts_max_age' => 0, 'hsts_subdomains' => false, 'frame_options' => 'ALLOW-FROM x', 'referrer_policy' => 'no-referrer'], 'frame_options'],
            [RequestRulePresets::CORS_STATIC, ['extensions' => ['woff2'], 'origin' => 'javascript:alert(1)'], 'origin'],
            [RequestRulePresets::CACHE_STATIC, ['extensions' => ['png'], 'edge_ttl_days' => 0, 'browser_ttl_days' => 1], 'edge_ttl_days'],
            [RequestRulePresets::CACHE_STATIC, ['extensions' => [], 'edge_ttl_days' => 1, 'browser_ttl_days' => 1], 'extensions'],
            [RequestRulePresets::BYPASS_PATHS, ['paths' => ['/login', 'admin']], 'paths'],
            [RequestRulePresets::BYPASS_COOKIES, ['cookies' => []], 'cookies'],
        ];
        $before = $domain->requestRules()->count();
        foreach ($refused as [$name, $input, $key]) {
            $this->actingAs($user)->putJson("$url/$name", $input)->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertSame($before, $domain->requestRules()->count());
        $this->assertSame(RequestRulePresets::BYPASS_PATHS, $domain->requestRules()->where('type', 'cache')->orderBy('priority')->orderBy('id')->first()->preset, 'a bypass sits before the static file rule');
    }

    public function test_stored_cache_path_rules_become_cache_rules_in_their_order(): void
    {
        [, $domain] = $this->ownedDomain();
        $untouched = Domain::query()->create(['name' => 'plain.example.test', 'display_name' => 'Plain', 'revision' => 4, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        DB::table('domains')->where('id', $domain->id)->update(['cache_settings' => json_encode(['mode' => 'standard', 'path_rules' => [
            ['match' => 'prefix', 'value' => '/static/', 'action' => 'ttl', 'ttl_seconds' => 3600],
            ['match' => 'suffix', 'value' => '.json', 'action' => 'bypass'],
            ['match' => 'prefix', 'value' => '/static/', 'action' => 'ttl', 'ttl_seconds' => 3600],
            ['match' => 'prefix', 'value' => '/admin', 'action' => 'bypass'],
        ]])]);
        DB::table('domains')->where('id', $untouched->id)->update(['cache_settings' => json_encode(['mode' => 'standard'])]);

        (require base_path('database/migrations/2026_11_21_000200_add_request_rule_types_and_move_cache_path_rules.php'))->up();

        $rules = $domain->requestRules()->orderBy('priority')->get();
        $this->assertSame([
            [[['field' => 'path', 'operator' => 'starts_with', 'value' => '/static/']], ['mode' => 'override', 'edge_ttl_seconds' => 3600], 10],
            [[['field' => 'path', 'operator' => 'ends_with', 'value' => '.json']], ['mode' => 'bypass'], 20],
            [[['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin']], ['mode' => 'bypass'], 40],
        ], $rules->map(fn ($rule): array => [$rule->conditions, $rule->action, $rule->priority])->all());
        $this->assertSame(['cache'], $rules->pluck('type')->unique()->values()->all());
        // The migrated rules carry the same fingerprint validation gives them, so an identical rule added later is a duplicate.
        $this->assertSame(RequestRuleData::validate(['type' => 'cache', 'priority' => 1, 'conditions' => $rules[0]->conditions, 'action' => $rules[0]->action])['fingerprint'], $rules[0]->fingerprint);
        $domain->refresh();
        $this->assertSame(['mode' => 'standard'], $domain->cache_settings);
        $this->assertSame(2, $domain->revision);
        $this->assertSame(4, $untouched->refresh()->revision);
        $this->assertSame(0, $untouched->requestRules()->count());
    }

    public function test_the_snapshot_carries_every_type_under_its_own_key(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain(proxied: true);
        $url = "/api/domains/{$domain->id}/request-rules";
        $this->actingAs($user)->postJson($url, $this->redirect())->assertAccepted();
        $this->actingAs($user)->postJson($url, $this->rule('rewrite', ['path' => 'prefix', 'from_prefix' => '/api', 'to_prefix' => '', 'query' => 'keep'], []))->assertAccepted();
        $this->actingAs($user)->postJson($url, $this->rule('header', ['request' => [], 'response' => [['op' => 'remove', 'name' => 'server']]], []))->assertAccepted();
        $this->actingAs($user)->postJson($url, $this->rule('cache', ['mode' => 'bypass']))->assertAccepted();
        $this->actingAs($user)->postJson($url, [...$this->rule('cache', ['mode' => 'override', 'edge_ttl_seconds' => 60], [['field' => 'path', 'operator' => 'starts_with', 'value' => '/off']]), 'enabled' => false])->assertAccepted();

        (new ReconcileEdgeDomain($domain->id))->handle();
        $rules = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail()->snapshot['rules'];
        $this->assertSame(['redirects', 'rewrites', 'headers', 'caches'], array_keys($rules));
        $this->assertSame([1, 1, 1, 1], array_map('count', array_values($rules)));
        $this->assertSame('', $rules['rewrites'][0]['action']['to_prefix']);
        $this->assertSame(['mode' => 'bypass'], $rules['caches'][0]['action']);
    }

    private function rule(string $type, array $action, ?array $conditions = null): array
    {
        return ['type' => $type, 'priority' => 100, 'action' => $action, 'conditions' => $conditions ?? [['field' => 'path', 'operator' => 'starts_with', 'value' => '/match']]];
    }

    public function test_rollback_restores_every_request_rule_type_and_refuses_an_invalid_revision_atomically(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain(proxied: true);
        $url = "/api/domains/{$domain->id}/request-rules";
        foreach ([
            $this->redirect(),
            $this->rule('rewrite', ['path' => 'set', 'path_value' => '/index.php', 'query' => 'keep']),
            $this->rule('header', ['request' => [], 'response' => [['op' => 'remove', 'name' => 'server']]]),
            $this->rule('cache', ['mode' => 'bypass']),
        ] as $input) {
            $this->actingAs($user)->postJson($url, $input)->assertAccepted();
        }
        (new ReconcileEdgeDomain($domain->id))->handle();
        $prior = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail();
        $expected = $domain->requestRules()->orderBy('type')->get()->map(fn ($rule): array => [$rule->type, $rule->conditions, $rule->action])->all();
        foreach ($domain->requestRules()->get() as $rule) {
            $this->actingAs($user)->deleteJson("$url/{$rule->id}")->assertAccepted();
        }
        $before = $domain->refresh()->revision;
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => $prior->revision])->assertAccepted();
        $this->assertSame($before + 1, $domain->refresh()->revision);
        $this->assertSame($expected, $domain->requestRules()->orderBy('type')->get()->map(fn ($rule): array => [$rule->type, $rule->conditions, $rule->action])->all());

        $snapshot = $prior->snapshot;
        $snapshot['rules']['headers'][0]['action']['response'][0] = ['op' => 'set', 'name' => 'host', 'value' => 'evil.example'];
        $prior->update(['snapshot' => $snapshot]);
        $before = $domain->revision;
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => $prior->revision])->assertUnprocessable();
        $this->assertSame($before, $domain->refresh()->revision);
        $this->assertSame($expected, $domain->requestRules()->orderBy('type')->get()->map(fn ($rule): array => [$rule->type, $rule->conditions, $rule->action])->all());
    }

    private function redirect(?array $action = null, ?array $conditions = null): array
    {
        return [
            'type' => 'redirect', 'priority' => 100,
            'conditions' => $conditions ?? [['field' => 'path', 'operator' => 'equals', 'value' => '/old']],
            'action' => $action ?? ['target' => 'url', 'url' => '/new', 'status_code' => 301, 'preserve_query' => false],
        ];
    }

    private function ownedDomain(bool $proxied = false): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.test', 'display_name' => 'Example', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user);
        if ($proxied) {
            $domain->dnsRecords()->create([
                'type' => 'A', 'name' => $domain->name, 'content' => '8.8.8.8', 'content_hash' => hash('sha256', 'origin'),
                'ttl' => 300, 'priority' => 0, 'weight' => 0, 'port' => 0, 'mode' => 'proxied',
                'origin' => ['scheme' => 'http', 'host' => '8.8.8.8', 'port' => 80, 'host_header' => $domain->name, 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => null],
            ]);
        }

        return [$user, $domain];
    }
}
