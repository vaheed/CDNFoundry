<?php

namespace Tests\Feature;

use App\Http\Controllers\WafController;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\EdgePool;
use App\Models\User;
use App\Support\ManagedWaf;
use App\Support\ManagedWafTuning;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagedWafTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_rechecks_assignment_inside_domain_transaction(): void
    {
        [$owner, $domain] = $this->ownedDomain();
        $request = Request::create("/api/domains/{$domain->id}/waf", 'PATCH', ['profile' => 'balanced']);
        $request->setUserResolver(fn () => $owner);
        Gate::after(function ($actor, $ability) use ($domain, $owner): void {
            if ($actor->is($owner) && $ability === 'update' && $domain->users()->whereKey($owner->id)->exists()) {
                $domain->users()->detach($owner);
            }
        });

        $this->expectException(AuthorizationException::class);
        try {
            app(WafController::class)->update($request, $domain);
        } finally {
            $this->assertSame('off', $domain->fresh()->waf_profile);
            $this->assertSame(1, $domain->fresh()->revision);
        }
    }

    public function test_profile_change_is_refused_with_409_when_no_waf_capable_pool_exists(): void
    {
        Queue::fake();
        [$owner, $domain] = $this->ownedDomain();

        $this->actingAs($owner)->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'balanced'])
            ->assertConflict()->assertJsonPath('code', 'waf_capable_pool_unavailable');
        $this->assertSame('off', $domain->fresh()->waf_profile);
        $this->assertSame(1, $domain->fresh()->revision);
        $this->actingAs($owner)->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'off'])->assertAccepted();
    }

    public function test_fixed_profiles_are_revisioned_authorized_and_idempotent(): void
    {
        Queue::fake();
        EdgePool::query()->where('kind', 'shared')->update(['waf_capable' => true, 'waf_runtime_version' => 'sha256:test']);
        [$owner, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/waf")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/waf")
            ->assertOk()->assertJsonPath('data.name', 'off');

        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->actingAs($owner)->withHeaders($headers)->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'balanced'])
            ->assertAccepted()->assertJsonPath('data.profile', 'balanced');
        $this->actingAs($owner)->withHeaders($headers)->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'strict'])
            ->assertConflict();
        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'custom', 'secrule' => 'SecRule ARGS attack'])
            ->assertUnprocessable()->assertJsonValidationErrors(['profile', 'secrule']);

        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertDatabaseHas('audit_logs', ['action' => 'waf.profile_updated', 'subject_id' => (string) $domain->id]);
        Queue::assertPushed(ReconcileEdgeDomain::class);
    }

    public function test_tuning_adjusts_categories_rules_paranoia_and_threshold_within_the_catalog(): void
    {
        Queue::fake();
        EdgePool::query()->where('kind', 'shared')->update(['waf_capable' => true, 'waf_runtime_version' => 'sha256:test']);
        [$owner, $domain] = $this->ownedDomain();
        $tuning = ['paranoia_level' => 3, 'inbound_threshold' => 8, 'disabled_categories' => ['xss'], 'disabled_rules' => [942100, 941100], 'log_only_rules' => [942120]];
        $this->actingAs($owner)->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'balanced', 'tuning' => $tuning])->assertAccepted();

        $compiled = ManagedWaf::compile($domain->refresh());
        $this->assertSame(3, $compiled['paranoia_level']);
        $this->assertSame(8, $compiled['inbound_threshold']);
        // A rule inside a switched-off category is covered by the category and is not listed again.
        $this->assertSame(['941'], $compiled['off_categories']);
        $this->assertSame([942100], $compiled['off_rules']);
        $this->assertSame([942120], $compiled['log_only_rules']);
        $this->assertSame('owasp-crs/4.26.0', ManagedWafTuning::catalog()['ruleset']);
        $this->assertStringStartsWith(ManagedWafTuning::catalog()['ruleset'], config('security.waf.ruleset'));

        // The tuning is replaced as a whole, kept when only the profile changes, and cleared with null.
        $this->actingAs($owner)->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'strict'])->assertAccepted();
        $this->assertSame(['xss'], $domain->refresh()->waf_tuning['disabled_categories']);
        $this->actingAs($owner)->patchJson("/api/domains/{$domain->id}/waf", ['tuning' => null])->assertAccepted();
        $this->assertNull($domain->refresh()->waf_tuning);
        $this->assertSame('strict', $domain->waf_profile);
        $this->assertSame(2, ManagedWaf::compile($domain)['paranoia_level']);

        // An Off domain stays off whatever its tuning says.
        $this->actingAs($owner)->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'off', 'tuning' => ['paranoia_level' => 4]])->assertAccepted();
        $this->assertSame(0, ManagedWaf::compile($domain->refresh())['paranoia_level']);
    }

    public function test_tuning_refuses_unknown_rules_bad_bounds_and_conflicts(): void
    {
        Queue::fake();
        EdgePool::query()->where('kind', 'shared')->update(['waf_capable' => true, 'waf_runtime_version' => 'sha256:test']);
        [$owner, $domain] = $this->ownedDomain();
        $cases = [
            ['paranoia_level' => 5], ['paranoia_level' => 0], ['inbound_threshold' => 101], ['inbound_threshold' => 0],
            ['disabled_categories' => ['nonsense']], ['disabled_rules' => [123456]], ['log_only_rules' => ['x']],
            ['disabled_rules' => [942100], 'log_only_rules' => [942100]], ['disabled_rules' => range(900000, 900100)],
            ['disabled_rules' => array_fill(0, 101, 942100)],
        ];
        foreach ($cases as $tuning) {
            $this->actingAs($owner)->patchJson("/api/domains/{$domain->id}/waf", ['profile' => 'balanced', 'tuning' => $tuning])->assertUnprocessable();
        }
        $this->assertNull($domain->refresh()->waf_tuning);
        $this->assertSame('off', $domain->waf_profile);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->patchJson("/api/domains/{$domain->id}/waf", ['tuning' => ['paranoia_level' => 2]])->assertNotFound();
    }

    public function test_the_rule_catalog_is_paginated_filtered_and_shows_each_rules_state(): void
    {
        [$owner, $domain] = $this->ownedDomain();
        $domain->update(['waf_tuning' => ['disabled_categories' => ['sqli'], 'disabled_rules' => [941100], 'log_only_rules' => [941110]]]);
        $first = $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/waf/rules")->assertOk();
        $this->assertCount(100, $first->json('data'));
        $this->assertNotNull($first->json('next_cursor'));
        $second = $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/waf/rules?cursor=".$first->json('next_cursor'))->assertOk();
        $this->assertSame($first->json('next_cursor') < $second->json('data.0.id'), true);

        $xss = collect($this->actingAs($owner)->getJson("/api/domains/{$domain->id}/waf/rules?category=xss")->json('data'))->keyBy('id');
        $this->assertSame('disabled', $xss[941100]['state']);
        $this->assertSame('log_only', $xss[941110]['state']);
        $this->assertSame('enabled', $xss->except([941100, 941110])->first()['state']);
        $sqli = $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/waf/rules?category=sqli&search=libinjection")->json('data');
        $this->assertSame('disabled', $sqli[0]['state']);
        $this->actingAs(User::factory()->create())->getJson("/api/domains/{$domain->id}/waf/rules")->assertNotFound();
    }

    public function test_new_exclusions_are_refused_and_stored_exclusions_never_reach_the_edge(): void
    {
        Queue::fake();
        [$owner, $domain] = $this->ownedDomain();
        $response = $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/waf/exclusions", [
            'dimension' => 'path', 'value' => '/checkout', 'reason' => 'Approved false positive for checkout',
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ])->assertConflict()->assertJsonPath('code', 'waf_exclusions_unavailable');
        $this->assertSame(0, $domain->wafExclusions()->count());
        $this->assertSame($domain->revision, $domain->refresh()->revision);

        $domain->wafExclusions()->create([
            'dimension' => 'rule', 'value' => 'SQL injection', 'rule_id' => 942100, 'reason' => 'Stored before the exclusion freeze',
            'expires_at' => now()->addDays(7), 'owner_id' => $owner->id,
        ]);
        $this->assertSame([], ManagedWaf::compile($domain->refresh())['exclusions']);
    }

    public function test_pool_capability_accepts_all_managed_profiles_without_canary_state(): void
    {
        $admin = User::factory()->admin()->create();
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $this->assertFalse($shared->waf_capable);
        $this->actingAs($admin)->patchJson("/api/admin/edge-pools/{$shared->id}", [
            'waf_capable' => true, 'waf_runtime_version' => 'sha256:test',
        ])->assertAccepted();
        $this->assertTrue($shared->refresh()->waf_capable);

        $this->assertFalse(ManagedWaf::profile('monitor')['blocking']);
        $this->assertTrue(ManagedWaf::profile('balanced')['blocking']);
        $this->assertSame(3, ManagedWaf::profile('strict')['inbound_threshold']);
        $this->assertLessThan(ManagedWaf::profile('balanced')['body_limit_bytes'], ManagedWaf::profile('strict')['body_limit_bytes']);
    }

    public function test_due_exclusions_expire_in_one_revision_and_queue_reconciliation(): void
    {
        Queue::fake();
        [$owner, $domain] = $this->ownedDomain();
        $domain->wafExclusions()->create([
            'dimension' => 'path', 'value' => '/temporary', 'reason' => 'Approved temporary false positive',
            'owner_id' => $owner->id, 'expires_at' => now()->subMinute(),
        ]);
        $this->artisan('cdnf:waf:expire-exclusions')->expectsOutput('Expired 1 managed WAF exclusion(s) across 1 domain(s).')->assertSuccessful();
        $this->assertDatabaseMissing('waf_exclusions', ['domain_id' => $domain->id]);
        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertDatabaseHas('audit_logs', ['action' => 'waf.exclusions_expired', 'subject_id' => (string) $domain->id]);
        Queue::assertPushed(ReconcileEdgeDomain::class);
    }

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'waf.example.test', 'display_name' => 'WAF', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user);

        return [$user, $domain];
    }
}
