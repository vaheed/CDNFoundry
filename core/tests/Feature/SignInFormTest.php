<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SignInFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_correct_password_signs_in_and_a_wrong_one_is_a_credentials_error_not_a_required_error(): void
    {
        Filament::setCurrentPanel('admin');
        User::factory()->admin()->create(['email' => 'root@example.test', 'password' => 'Correct-Horse-9']);

        Livewire::test(Login::class)->fillForm(['email' => 'root@example.test', 'password' => 'Wrong-Horse-99'])->call('authenticate')
            ->assertHasErrors(['data.email'])->assertHasNoErrors(['data.password']);
        Livewire::test(Login::class)->fillForm(['email' => 'root@example.test', 'password' => 'Correct-Horse-9'])->call('authenticate')
            ->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_an_enrolled_administrator_is_asked_for_the_code_and_not_the_password_again(): void
    {
        Filament::setCurrentPanel('admin');
        $google = new Google2FA;
        $secret = $google->generateSecretKey();
        User::factory()->admin()->create(['email' => 'root@example.test', 'password' => 'Correct-Horse-9'])->forceFill(['app_authentication_secret' => $secret])->save();

        $page = Livewire::test(Login::class)->fillForm(['email' => 'root@example.test', 'password' => 'Correct-Horse-9'])->call('authenticate')->assertHasNoErrors();
        $this->assertGuest();
        $page->set('data.multiFactor.app.code', $google->getCurrentOtp($secret))->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticated();
    }
}
