<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\SecurityRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Nested routes and idempotency receipts never cross from one assigned domain, or one user, to another. */
class ApiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDomain(string $name): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user);

        return [$user, $domain];
    }

    private function rule(User $user, Domain $domain): int
    {
        $body = ['name' => 'block admin', 'action' => 'block', 'priority' => 1, 'conditions' => [['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin']]];

        return $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $body)->assertCreated()->json('data.id');
    }

    public function test_a_nested_resource_of_another_domain_is_not_reachable_through_an_own_or_a_foreign_domain(): void
    {
        Queue::fake();
        [$alice, $first] = $this->ownedDomain('first.example.com');
        [$bob, $second] = $this->ownedDomain('second.example.com');
        $foreignRule = $this->rule($bob, $second);
        $revision = $second->refresh()->revision;

        $this->actingAs($alice)->getJson("/api/domains/{$second->id}/security/rules")->assertNotFound();
        $this->actingAs($alice)->deleteJson("/api/domains/{$second->id}/security/rules/{$foreignRule}")->assertNotFound();
        // The own domain with a foreign rule identifier is a mismatch, never a way to the foreign rule.
        $this->actingAs($alice)->patchJson("/api/domains/{$first->id}/security/rules/{$foreignRule}", ['name' => 'x', 'action' => 'allow', 'priority' => 1, 'conditions' => []])->assertNotFound();
        $this->actingAs($alice)->deleteJson("/api/domains/{$first->id}/security/rules/{$foreignRule}")->assertNotFound();

        $this->assertTrue(SecurityRule::query()->whereKey($foreignRule)->exists());
        $this->assertSame($revision, $second->refresh()->revision);
        $this->assertSame(0, $first->refresh()->securityRules()->count());
    }

    public function test_an_idempotency_key_is_private_to_its_user(): void
    {
        Queue::fake();
        [$alice, $first] = $this->ownedDomain('first.example.com');
        [$bob, $second] = $this->ownedDomain('second.example.com');
        $key = (string) Str::uuid();
        $body = ['name' => 'block admin', 'action' => 'block', 'priority' => 1, 'conditions' => [['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin']]];

        $created = $this->actingAs($alice)->withHeader('Idempotency-Key', $key)->postJson("/api/domains/{$first->id}/security/rules", $body)->assertCreated();
        // The same key from another user, even with the same body, is a new request on that user's own domain and never a replay.
        $other = $this->actingAs($bob)->withHeader('Idempotency-Key', $key)->postJson("/api/domains/{$second->id}/security/rules", $body)->assertCreated();
        $other->assertHeaderMissing('Idempotency-Replayed');
        $this->assertNotSame($created->json('data.id'), $other->json('data.id'));
        $this->assertSame(1, $first->securityRules()->count());
        $this->assertSame(1, $second->securityRules()->count());
        // The owner's own repeat is the replay, and it changes nothing.
        $this->actingAs($alice)->withHeader('Idempotency-Key', $key)->postJson("/api/domains/{$first->id}/security/rules", $body)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, $first->securityRules()->count());
    }

    public function test_a_revoked_token_stops_working_on_the_next_request(): void
    {
        [$alice, $domain] = $this->ownedDomain('first.example.com');
        $token = $alice->createToken('ci', ['*'], now()->addDay());
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson("/api/domains/{$domain->id}")->assertOk();

        $token->accessToken->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson("/api/domains/{$domain->id}")->assertUnauthorized();
    }
}
