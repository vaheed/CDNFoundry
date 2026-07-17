<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_compiled_view_directory_exists_and_is_writable(): void
    {
        $compiledViewPath = config('view.compiled');

        $this->assertDirectoryExists($compiledViewPath);
        $this->assertDirectoryIsWritable($compiledViewPath);
        if (function_exists('posix_geteuid')) {
            $this->assertSame((string) posix_geteuid(), basename($compiledViewPath));
        }
    }

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

    public function test_administrator_operational_pages_render_and_domain_users_cannot_open_them(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach (['/admin/users', '/admin/domains', '/admin/dns-clusters', '/admin/operations', '/admin/audit-logs', '/admin/system-dns-identity', '/admin/tokens', '/admin/profile'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
            $this->actingAs($user)->get($path)->assertForbidden();
        }

        $this->actingAs($user)->get('/app/tokens')->assertOk();
        $this->actingAs($user)->get('/app/profile')->assertOk();
    }

    public function test_domain_resource_is_scoped_to_assignments_in_the_user_panel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.test', 'display_name' => 'example.test']);
        $domain->users()->attach($user);

        $this->actingAs($user)->get('/app/domains')->assertOk();
        $this->actingAs($user)->get("/app/domains/{$domain->id}")->assertOk();
        $this->actingAs($other)->get("/app/domains/{$domain->id}")->assertNotFound();
    }

    public function test_domain_view_relation_managers_are_mutable_in_both_panels(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'mutable.example.test', 'display_name' => 'mutable.example.test']);
        $domain->users()->attach($user);

        $this->assertFalse(Filament::getPanel('admin')->hasReadOnlyRelationManagersOnResourceViewPagesByDefault());
        $this->assertFalse(Filament::getPanel('app')->hasReadOnlyRelationManagersOnResourceViewPagesByDefault());
        $this->actingAs($admin)->get("/admin/domains/{$domain->id}")->assertOk();
        $this->actingAs($user)->get("/app/domains/{$domain->id}")->assertOk();
    }

    public function test_horizon_is_available_only_to_active_administrators(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $disabledAdmin = User::factory()->admin()->disabled()->create();

        $this->actingAs($admin)->get('/horizon')->assertOk();
        $this->actingAs($user)->get('/horizon')->assertForbidden();
        $this->actingAs($disabledAdmin)->get('/horizon')->assertForbidden();
    }
}
