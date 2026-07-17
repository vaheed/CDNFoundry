<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_read_profile_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'CorrectHorseBattery9']);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectHorseBattery9',
            'device_name' => 'test runner',
        ])->assertOk()->assertJsonPath('data.user.email', $user->email);

        $token = $login->json('data.token');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
    }

    public function test_disabled_user_cannot_login(): void
    {
        $user = User::factory()->disabled()->create(['password' => 'CorrectHorseBattery9']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'CorrectHorseBattery9'])
            ->assertForbidden()->assertJsonPath('error.code', 'account_disabled');
    }

    public function test_domain_user_cannot_access_admin_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admin_can_create_disable_and_enable_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $key = (string) Str::uuid();
        $created = $this->actingAs($admin)->withHeader('Idempotency-Key', $key)->postJson('/api/admin/users', [
            'name' => 'Domain Operator',
            'email' => 'operator@example.test',
            'password' => 'StrongPassword123',
            'type' => UserType::User->value,
        ])->assertCreated()->assertJsonPath('data.type', 'user');
        $userId = $created->json('data.id');

        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/admin/users/$userId/disable")->assertOk();
        $this->assertNotNull(User::findOrFail($userId)->disabled_at);
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/admin/users/$userId/enable")->assertOk();
        $this->assertNull(User::findOrFail($userId)->disabled_at);
        $this->assertSame(3, AuditLog::query()->where('subject_id', $userId)->count());
    }

    public function test_admin_cannot_disable_or_demote_self(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/admin/users/$admin->id/disable")->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/admin/users/$admin->id", ['type' => 'user'])->assertUnprocessable();
    }

    public function test_idempotency_replays_same_request_and_rejects_different_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $key = (string) Str::uuid();
        $payload = ['name' => 'One', 'email' => 'one@example.test', 'password' => 'StrongPassword123', 'type' => 'user'];

        $first = $this->actingAs($admin)->withHeader('Idempotency-Key', $key)->postJson('/api/admin/users', $payload)->assertCreated();
        $this->actingAs($admin)->withHeader('Idempotency-Key', $key)->postJson('/api/admin/users', $payload)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->actingAs($admin)->withHeader('Idempotency-Key', $key)->postJson('/api/admin/users', [...$payload, 'name' => 'Changed'])
            ->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_token_is_shown_once_and_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $created = $this->actingAs($user)->postJson('/api/me/tokens', ['name' => 'automation'])
            ->assertCreated()->assertJsonStructure(['data' => ['id', 'name', 'token']]);
        $tokenId = $created->json('data.id');
        $this->actingAs($user)->getJson('/api/me/tokens')
            ->assertOk()
            ->assertJsonPath('data.0.token_last_six', substr($created->json('data.token'), -6))
            ->assertJsonMissing(['token' => $created->json('data.token')]);
        $this->actingAs($user)->deleteJson("/api/me/tokens/$tokenId")->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_profile_and_password_mutations_are_audited_and_revoke_other_tokens(): void
    {
        $user = User::factory()->create(['password' => 'CorrectHorseBattery9']);
        $first = $user->createToken('first')->accessToken;
        $second = $user->createToken('second')->accessToken;

        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())->patchJson('/api/me', [
            'name' => 'Updated Operator',
            'email' => 'updated@example.test',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Operator');

        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())->putJson('/api/me/password', [
            'current_password' => 'CorrectHorseBattery9',
            'password' => 'AnotherStrongPassword9',
            'password_confirmation' => 'AnotherStrongPassword9',
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $second->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'profile.updated']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'profile.password_changed']);
    }

    public function test_disabling_a_user_revokes_existing_tokens_and_blocks_the_next_request(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('automation')->plainTextToken;

        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/users/{$user->id}/disable")->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($plainTextToken)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_user_deletion_requires_token_revocation_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $user->createToken('still-active');

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$user->id}")
            ->assertConflict()->assertJsonPath('code', 'conflict');

        $user->tokens()->delete();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->deleteJson("/api/admin/users/{$user->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.deleted']);
    }

    public function test_validation_errors_have_the_stable_api_shape(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['message', 'code', 'errors' => ['email', 'password']]);
    }
}
