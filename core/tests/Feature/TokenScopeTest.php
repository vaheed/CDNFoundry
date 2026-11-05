<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Each call authenticates afresh: the test kernel would otherwise keep the first request's guard user. */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function issue(User $user, array $body): string
    {
        $full = $user->createToken('full', ['*'], now()->addDay())->plainTextToken;

        return $this->as($full)->postJson('/api/me/tokens', ['name' => 'scoped'] + $body)->assertCreated()->json('data.token');
    }

    public function test_tokens_expire_by_default_and_the_lifetime_is_bounded(): void
    {
        $user = User::factory()->create();
        $full = $user->createToken('full', ['*'], now()->addDay())->plainTextToken;

        $created = $this->as($full)->postJson('/api/me/tokens', ['name' => 'ci'])->assertCreated();
        $this->assertEqualsWithDelta(90, now()->diffInDays($created->json('data.expires_at')), 1);
        $this->as($full)->postJson('/api/me/tokens', ['name' => 'forever', 'expires_in_days' => 4000])->assertUnprocessable();

        $this->travel(91)->days();
        $this->app['auth']->forgetGuards();
        $this->as($created->json('data.token'))->getJson('/api/me')->assertUnauthorized();
    }

    public function test_a_read_token_cannot_write_and_cannot_mint_tokens(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'scoped.example.com', 'display_name' => 'Scoped']);
        $domain->users()->attach($user);
        $token = $this->issue($user, ['abilities' => ['read']]);

        $this->as($token)->getJson("/api/domains/{$domain->id}")->assertOk();
        $this->as($token)->patchJson("/api/domains/{$domain->id}", ['display_name' => 'x'])->assertForbidden()->assertJsonPath('code', 'token_scope_exceeded');
        $this->as($token)->postJson('/api/me/tokens', ['name' => 'wider'])->assertForbidden();
        $this->as($token)->getJson('/api/me/tokens')->assertForbidden();
        $this->as($token)->putJson('/api/me/password', [])->assertForbidden();
    }

    public function test_a_dns_token_reaches_only_dns_routes_and_a_purge_token_only_purges(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'abilities.example.com', 'display_name' => 'Abilities']);
        $domain->users()->attach($user);
        $dns = $this->issue($user, ['abilities' => ['dns']]);
        $purge = $this->issue($user, ['abilities' => ['purge']]);

        $this->as($dns)->getJson("/api/domains/{$domain->id}/dns/records")->assertOk();
        $this->as($dns)->getJson("/api/domains/{$domain->id}")->assertForbidden();
        $this->as($dns)->postJson("/api/domains/{$domain->id}/cache/purge", ['everything' => true])->assertForbidden();
        $this->as($purge)->getJson("/api/domains/{$domain->id}/dns/records")->assertForbidden();
        $this->as($purge)->getJson("/api/domains/{$domain->id}/cache/purges")->assertOk();
        $this->as($purge)->postJson("/api/domains/{$domain->id}/dns/records", [])->assertForbidden();
    }

    public function test_a_domain_scoped_token_sees_only_its_domains_even_for_an_administrator(): void
    {
        $admin = User::factory()->create(['type' => UserType::Admin]);
        $first = Domain::query()->create(['name' => 'first.example.com', 'display_name' => 'First']);
        $second = Domain::query()->create(['name' => 'second.example.com', 'display_name' => 'Second']);
        $token = $this->issue($admin, ['abilities' => ['*'], 'domain_ids' => [$first->id]]);

        $this->as($token)->getJson('/api/domains')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->as($token)->getJson("/api/domains/{$first->id}")->assertOk();
        $this->as($token)->getJson("/api/domains/{$second->id}")->assertNotFound();
        $this->as($token)->getJson('/api/admin/users')->assertForbidden();
        $this->as($token)->postJson('/api/me/tokens', ['name' => 'wider'])->assertForbidden();
    }

    public function test_a_token_cannot_be_scoped_to_a_domain_its_owner_cannot_see(): void
    {
        $user = User::factory()->create();
        $foreign = Domain::query()->create(['name' => 'foreign.example.com', 'display_name' => 'Foreign']);
        $full = $user->createToken('full', ['*'], now()->addDay())->plainTextToken;

        $this->as($full)->postJson('/api/me/tokens', ['name' => 'bad', 'domain_ids' => [$foreign->id]])->assertStatus(403);
        $this->assertDatabaseCount('personal_access_token_domains', 0);
    }
}
