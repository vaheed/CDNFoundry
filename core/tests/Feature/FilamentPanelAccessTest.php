<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_correct_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/app')->assertRedirect('/app/login');
    }

    public function test_each_user_type_can_access_only_its_panel(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/app')->assertForbidden();
        $this->actingAs($user)->get('/app')->assertOk();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_disabled_users_cannot_access_either_panel(): void
    {
        $disabledAdmin = User::factory()->admin()->disabled()->create();
        $disabledUser = User::factory()->disabled()->create();

        $this->actingAs($disabledAdmin)->get('/admin')->assertForbidden();
        $this->actingAs($disabledUser)->get('/app')->assertForbidden();
    }
}
