<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\IpListData;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpListsTest extends TestCase
{
    use RefreshDatabase;

    private function domain(string $name = 'lists.example'): Domain
    {
        return Domain::query()->create(['name' => $name, 'display_name' => $name, 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
    }

    private function rule(string $list, string $operator = 'equals', string $action = 'block', int $priority = 10): array
    {
        return ['name' => 'List rule', 'conditions' => [['field' => 'client_ip_list', 'operator' => $operator, 'value' => $list]], 'action' => $action, 'priority' => $priority];
    }

    public function test_entries_are_canonical_unique_and_sorted(): void
    {
        $entries = IpListData::normalize(['203.0.113.77', '203.0.113.0/24', '2001:DB8::1', '2001:db8:0:0:0:0:0:1', '::ffff:198.51.100.9', '10.1.2.3/8', '2001:db8:abcd:12::99/64']);

        $this->assertSame(['10.0.0.0/8', '198.51.100.9/32', '2001:db8::1/128', '2001:db8:abcd:12::/64', '203.0.113.0/24', '203.0.113.77/32'], $entries);
    }

    public function test_compile_merges_overlaps_and_orders_ranges_for_both_families(): void
    {
        $compiled = IpListData::compile(IpListData::normalize(['10.0.0.0/8', '10.1.0.0/16', '192.0.2.0/25', '192.0.2.128/25', '2001:db8::/32', '2001:db8:1::/48', '::1']));

        // 10.0.0.0/8 swallows 10.1.0.0/16; the two /25 touch but stay separate ranges; IPv6 is four words per address.
        $this->assertSame([167772160, 184549375, 3221225984, 3221226111, 3221226112, 3221226239], $compiled['v4']);
        $this->assertSame([0, 0, 0, 1, 0, 0, 0, 1, 0x20010DB8, 0, 0, 0, 0x20010DB8, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF], $compiled['v6']);
    }

    public function test_an_unused_list_is_stored_without_touching_the_edge(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->putJson("/api/domains/{$domain->id}/security/ip-lists/office", ['description' => 'Office', 'entries' => ['203.0.113.0/24', '2001:db8::/48']])
            ->assertOk()->assertJsonPath('data.entry_count', 2)->assertJsonPath('data.operation_id', null);

        Queue::assertNothingPushed();
        $this->assertSame(1, $domain->refresh()->revision);
        $this->assertSame(['2001:db8::/48', '203.0.113.0/24'], $domain->ipLists()->firstOrFail()->entries);
        $this->actingAs($admin)->getJson("/api/domains/{$domain->id}/security/ip-lists/office")->assertOk()->assertJsonPath('data.entries.0', '2001:db8::/48');
    }

    public function test_a_rule_using_a_list_puts_its_compiled_ranges_in_the_snapshot_and_a_change_is_one_revision(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->putJson("/api/domains/{$domain->id}/security/ip-lists/office", ['entries' => ['203.0.113.0/24']])->assertOk();
        $this->actingAs($admin)->postJson("/api/domains/{$domain->id}/security/rules", $this->rule('office'))->assertCreated();

        $security = SecurityConfig::compile($domain->refresh());
        $this->assertSame(['office' => ['v4' => [3405803776, 3405804031], 'v6' => []]], $security['ip_lists']);
        $this->assertSame('client_ip_list', $security['rules'][0]['conditions'][0]['field']);

        $revision = $domain->revision;
        Queue::fake();
        $this->actingAs($admin)->patchJson("/api/domains/{$domain->id}/security/ip-lists/office", ['add' => ['198.51.100.0/24'], 'remove' => []])->assertAccepted()->assertJsonPath('data.entry_count', 2);
        $this->assertSame($revision + 1, $domain->refresh()->revision);
        Queue::assertPushed(ReconcileEdgeDomain::class, 1);
        $this->assertCount(4, SecurityConfig::compile($domain)['ip_lists']['office']['v4']);
    }

    public function test_a_list_no_enabled_rule_uses_is_left_out_of_the_snapshot(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->putJson("/api/domains/{$domain->id}/security/ip-lists/spare", ['entries' => ['203.0.113.0/24']])->assertOk();

        $this->assertSame([], SecurityConfig::compile($domain)['ip_lists']);
    }

    public function test_rules_must_name_an_existing_list_and_a_used_list_cannot_be_deleted(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/domains/{$domain->id}/security/rules", $this->rule('missing'))->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');
        $this->actingAs($admin)->putJson("/api/domains/{$domain->id}/security/ip-lists/office", ['entries' => ['203.0.113.0/24']])->assertOk();
        $this->actingAs($admin)->postJson("/api/domains/{$domain->id}/security/rules", $this->rule('office', 'not_equals', 'allow'))->assertCreated();

        $this->actingAs($admin)->deleteJson("/api/domains/{$domain->id}/security/ip-lists/office")->assertConflict();
        $this->actingAs($admin)->postJson("/api/domains/{$domain->id}/security/rules", ['conditions' => [['field' => 'client_ip_list', 'operator' => 'equals', 'value' => 'Bad Name']], 'action' => 'block', 'priority' => 1])->assertUnprocessable();

        $domain->securityRules()->delete();
        $this->actingAs($admin)->deleteJson("/api/domains/{$domain->id}/security/ip-lists/office")->assertNoContent();
    }

    public function test_limits_and_validation(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $admin = User::factory()->admin()->create();
        $url = "/api/domains/{$domain->id}/security/ip-lists";

        $this->actingAs($admin)->putJson("$url/office", ['entries' => ['not-an-address']])->assertUnprocessable()->assertJsonValidationErrors('entries.0');
        $this->actingAs($admin)->putJson("$url/office", ['entries' => ['10.0.0.0/33']])->assertUnprocessable();
        $this->actingAs($admin)->putJson("$url/Office", ['entries' => []])->assertUnprocessable();
        $big = array_map(fn (int $i): string => long2ip(167772160 + $i), range(1, 10001));
        $this->actingAs($admin)->putJson("$url/office", ['entries' => $big])->assertUnprocessable();
        $this->actingAs($admin)->putJson("$url/office", ['entries' => array_slice($big, 0, 10000)])->assertOk()->assertJsonPath('data.entry_count', 10000);
        $this->actingAs($admin)->patchJson("$url/office", ['add' => ['198.51.100.1']])->assertUnprocessable();
        foreach (['two', 'three', 'four', 'five'] as $name) {
            $this->actingAs($admin)->putJson("$url/$name", ['entries' => []])->assertOk();
        }
        $this->actingAs($admin)->putJson("$url/six", ['entries' => []])->assertConflict();
    }

    public function test_only_people_with_access_to_the_domain_change_its_lists(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $outsider = User::factory()->create();
        $owner = User::factory()->create();
        $domain->users()->attach($owner->id);
        $url = "/api/domains/{$domain->id}/security/ip-lists/office";

        $this->actingAs($outsider)->putJson($url, ['entries' => []])->assertNotFound();
        $this->actingAs($owner)->putJson($url, ['entries' => ['203.0.113.9']])->assertOk();
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
    }

    public function test_a_platform_rule_cannot_use_a_domain_list(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $id = $this->actingAs($admin)->postJson('/api/admin/platform-rulesets', ['name' => 'Shared', 'applies_to_all' => true])->json('data.id');

        $this->actingAs($admin)->postJson("/api/admin/platform-rulesets/$id/rules", $this->rule('office'))->assertUnprocessable();
    }
}
