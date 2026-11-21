<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\User;
use App\Support\RequestRuleData;
use App\Support\RequestRulePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RequestRuleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_redirect_rule_is_authorized_revisioned_audited_and_queued(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();
        $admin = User::factory()->create(['type' => 'admin']);

        $this->actingAs($stranger)->postJson("/api/domains/{$domain->id}/request-rules", $this->redirect())->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/request-rules")->assertNotFound();

        $created = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/request-rules", $this->redirect())
            ->assertCreated()->assertJsonPath('data.type', 'redirect')->assertJsonPath('data.action.status_code', 301)
            ->assertJsonStructure(['data' => ['id', 'operation_id', 'status']]);
        $this->assertSame(2, $domain->refresh()->revision);
        Queue::assertPushed(ReconcileEdgeDomain::class, fn ($job): bool => $job->domainId === $domain->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request_rule.created', 'subject_id' => (string) $created->json('data.id')]);

        $this->actingAs($admin)->getJson("/api/domains/{$domain->id}/request-rules?type=redirect")->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/request-rules?type=nonsense")->assertUnprocessable();
    }

    public function test_every_target_is_validated_and_stored_in_its_typed_shape(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/request-rules";

        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'host', 'host' => 'Example.Test', 'scheme' => 'https', 'status_code' => 308, 'preserve_query' => true],
            [['field' => 'hostname', 'operator' => 'equals', 'value' => 'www.example.test']]))->assertCreated()->assertJsonPath('data.action.host', 'example.test');
        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'prefix', 'from_prefix' => '/blog', 'to_prefix' => '/news', 'status_code' => 302, 'preserve_query' => false], []))
            ->assertCreated()->assertJsonPath('data.conditions', []);
        $this->actingAs($user)->postJson($url, $this->redirect(['target' => 'url', 'url' => 'https://shop.example/sale?from=cdn', 'status_code' => 307, 'preserve_query' => true],
            [['field' => 'client_ip', 'operator' => 'equals', 'value' => '2001:db8:0:0::/48']]))
            ->assertCreated()->assertJsonPath('data.conditions.0.value', '2001:db8::/48');

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
        $this->actingAs($user)->postJson($url, $this->redirect())->assertCreated();
        $this->actingAs($user)->postJson($url, [...$this->redirect(), 'name' => 'Same rule'])->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $this->actingAs($user)->postJson($url, $this->redirect(null, [['field' => 'path', 'operator' => 'equals', 'value' => '/two']]))->assertCreated();
        $this->actingAs($user)->postJson($url, $this->redirect(null, [['field' => 'path', 'operator' => 'equals', 'value' => '/three']]))->assertConflict();
        $this->assertSame(2, $domain->requestRules()->count());
    }

    public function test_a_replayed_creation_creates_one_rule_and_a_changed_replay_conflicts(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $headers = ['Idempotency-Key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
        $url = "/api/domains/{$domain->id}/request-rules";
        $first = $this->actingAs($user)->withHeaders($headers)->postJson($url, $this->redirect())->assertCreated();
        $this->actingAs($user)->withHeaders($headers)->postJson($url, $this->redirect())->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
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
