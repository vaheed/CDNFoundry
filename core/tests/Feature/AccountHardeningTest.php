<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Edge;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AccountHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_limited_per_account_whatever_the_address(): void
    {
        RateLimiter::clear('account|victim@example.com');
        User::factory()->create(['email' => 'victim@example.com', 'password' => 'CorrectHorseBattery9']);
        $limit = (int) app(PlatformSettings::class)->integer('rate_limits', 'login_per_minute');

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($attempt + 1)])
                ->postJson('/api/auth/login', ['email' => 'victim@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->postJson('/api/auth/login', ['email' => 'victim@example.com', 'password' => 'CorrectHorseBattery9'])->assertStatus(429);
    }

    public function test_an_unknown_address_and_a_wrong_password_answer_identically(): void
    {
        User::factory()->create(['email' => 'real@example.com']);

        $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever-1'])->assertUnprocessable();
        $wrong = $this->postJson('/api/auth/login', ['email' => 'real@example.com', 'password' => 'whatever-1'])->assertUnprocessable();

        $this->assertSame($unknown->json('errors.email'), $wrong->json('errors.email'));
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'CorrectHorseBattery9']);
        $token = $user->createToken('t', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($token)->patchJson('/api/me', ['name' => 'New', 'email' => 'new@example.com'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->patchJson('/api/me', ['name' => 'New', 'email' => 'new@example.com', 'current_password' => 'CorrectHorseBattery9'])->assertOk();
        $this->assertSame('new@example.com', $user->refresh()->email);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->patchJson('/api/me', ['name' => 'Renamed', 'email' => 'new@example.com'])->assertOk();
    }

    public function test_every_user_follows_one_password_policy(): void
    {
        $admin = User::factory()->create(['type' => UserType::Admin]);
        $token = $admin->createToken('t', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($token)->putJson('/api/me/password', ['current_password' => 'password', 'password' => 'Short1aA', 'password_confirmation' => 'Short1aA'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_an_administrator_password_reset_revokes_the_users_tokens(): void
    {
        $admin = User::factory()->create(['type' => UserType::Admin]);
        $user = User::factory()->create();
        $user->createToken('stolen', ['*'], now()->addDay());
        $adminToken = $admin->createToken('t', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($adminToken)->patchJson("/api/admin/users/{$user->id}", ['password' => 'AnotherLongPass99'])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_two_step_sign_in_is_required_once_enrolled_and_recovery_codes_work_once(): void
    {
        $google = new Google2FA;
        $secret = $google->generateSecretKey();
        $admin = User::factory()->create(['type' => UserType::Admin, 'password' => 'CorrectHorseBattery9', 'email' => 'root@example.com']);
        $admin->saveAppAuthenticationSecret($secret);
        $admin->saveAppAuthenticationRecoveryCodes(['RECOVER-ONE']);
        $credentials = ['email' => 'root@example.com', 'password' => 'CorrectHorseBattery9'];

        $this->postJson('/api/auth/login', $credentials)->assertUnauthorized()->assertJsonPath('code', 'otp_required');
        $this->postJson('/api/auth/login', $credentials + ['otp' => '000000'])->assertUnprocessable();
        $this->postJson('/api/auth/login', $credentials + ['otp' => $google->getCurrentOtp($secret)])->assertOk();
        $this->postJson('/api/auth/login', $credentials + ['otp' => 'RECOVER-ONE'])->assertOk();
        $this->postJson('/api/auth/login', $credentials + ['otp' => 'RECOVER-ONE'])->assertUnprocessable();
        $this->assertNotSame($secret, $admin->refresh()->getRawOriginal('app_authentication_secret'));
    }

    public function test_an_unused_bootstrap_token_expires(): void
    {
        $edge = Edge::query()->create(['name' => 'fresh', 'country_code' => 'DE', 'continent_code' => 'EU', 'management_ipv4' => '203.0.113.88', 'enabled' => true]);
        $edge->update(['bootstrap_token_hash' => hash('sha256', str_repeat('a', 64)), 'bootstrap_consumed_at' => null, 'bootstrap_expires_at' => now()->subMinute()]);

        $this->postJson('/edge/v1/register', ['edge_id' => $edge->id, 'bootstrap_token' => str_repeat('a', 64), 'agent_version' => '1', 'certificate_request' => 'x'])
            ->assertUnauthorized();
    }
}
