<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_administrator_with_an_interactively_supplied_password(): void
    {
        $this->artisan('cdnf:admin:create', ['--name' => 'Operations Admin', '--email' => 'ADMIN@example.test'])
            ->expectsQuestion('Password (at least 12 characters)', 'CorrectHorseBattery9')
            ->expectsQuestion('Confirm password', 'CorrectHorseBattery9')
            ->expectsOutput('Administrator admin@example.test created.')
            ->assertSuccessful();

        $administrator = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame(UserType::Admin, $administrator->type);
        $this->assertTrue(Hash::check('CorrectHorseBattery9', $administrator->password));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.bootstrap_created',
            'subject_id' => $administrator->getKey(),
        ]);
    }

    public function test_it_rejects_a_duplicate_email_or_mismatched_password_without_writing(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $this->artisan('cdnf:admin:create', ['--name' => 'Duplicate', '--email' => 'existing@example.test'])
            ->expectsQuestion('Password (at least 12 characters)', 'CorrectHorseBattery9')
            ->expectsQuestion('Confirm password', 'DifferentPassword9')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
