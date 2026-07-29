<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\EdgePool;
use App\Models\User;
use App\Support\ManagedWaf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagedWafTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_profiles_are_revisioned_authorized_and_idempotent(): void
    {
        Queue::fake();
        [$owner, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/waf")->assertForbidden();
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

    public function test_exclusions_are_literal_owned_audited_expiring_and_bounded(): void
    {
        Queue::fake();
        [$owner, $domain] = $this->ownedDomain();
        $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/waf/exclusions", [
            'dimension' => 'path', 'value' => '/checkout', 'reason' => 'Approved false positive for checkout',
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ])->assertAccepted()->assertJsonPath('data.exclusion.owner_id', $owner->id);
        $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/waf/exclusions", [
            'dimension' => 'path', 'value' => '/checkout/*', 'reason' => 'Unsafe wildcard request',
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/waf/exclusions", [
            'dimension' => 'rule', 'value' => 'SQL injection', 'reason' => 'Missing bounded rule identifier',
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('rule_id');
        $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/waf/exclusions", [
            'dimension' => 'rule', 'value' => 'SQL injection', 'rule_id' => 942100,
            'reason' => 'Arbitrary configuration is forbidden',
            'expires_at' => now()->addDays(31)->toIso8601String(), 'secrule' => 'SecRuleEngine Off',
        ])->assertUnprocessable()->assertJsonValidationErrors(['expires_at', 'secrule']);

        $compiled = ManagedWaf::compile($domain->refresh());
        $this->assertCount(1, $compiled['exclusions']);
        $this->assertSame('/checkout', $compiled['exclusions'][0]['value']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'waf.exclusion_created']);
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
