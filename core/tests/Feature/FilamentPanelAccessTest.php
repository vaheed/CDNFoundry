<?php

namespace Tests\Feature;

use App\Models\DnsCluster;
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
        foreach ([1, 2] as $index) {
            DnsCluster::query()->create([
                'name' => "dashboard-dns-{$index}",
                'location' => "test-{$index}",
                'enabled' => true,
                'api_url' => "http://dashboard-dns-{$index}.test",
                'api_key' => "dashboard-secret-{$index}",
                'nameservers' => ["ns1.dashboard-{$index}.test", "ns2.dashboard-{$index}.test"],
                'last_health_status' => 'healthy',
            ]);
        }

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Control-plane health')->assertSee('Queue lanes')->assertSee('2 healthy and enabled');
        $this->actingAs($admin)->get('/app')->assertForbidden();
        $this->actingAs($user)->get('/app')->assertOk()->assertSee('Assigned domains')->assertSee('Start serving a domain');
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_both_panels_register_the_compiled_shared_theme(): void
    {
        $theme = 'resources/css/filament/shared/theme.css';

        $this->assertSame($theme, Filament::getPanel('admin')->getViteTheme());
        $this->assertSame($theme, Filament::getPanel('app')->getViteTheme());
        $this->assertFileExists(public_path('build/manifest.json'));
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey($theme, $manifest);
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

        foreach (['/admin/users', '/admin/domains', '/admin/dns-clusters', '/admin/edges', '/admin/edge-pools', '/admin/operations', '/admin/audit-logs', '/admin/system-dns-identity', '/admin/platform-settings', '/admin/telemetry', '/admin/tokens', '/admin/profile'] as $path) {
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

    public function test_domain_dashboard_lists_only_assigned_recent_domains(): void
    {
        $user = User::factory()->create();
        $assigned = Domain::query()->create(['name' => 'assigned-dashboard.example.test', 'display_name' => 'Assigned dashboard domain']);
        $unassigned = Domain::query()->create(['name' => 'private-dashboard.example.test', 'display_name' => 'Private dashboard domain']);
        $assigned->users()->attach($user);

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertSee($assigned->display_name)
            ->assertDontSee($unassigned->display_name);
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

    public function test_domain_view_renders_structured_proxy_settings_without_treating_each_value_as_an_entry(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create([
            'name' => 'proxy-settings.example.test',
            'display_name' => 'Proxy settings example',
            'proxy_settings' => [
                'enabled' => true,
                'redirect_https' => false,
                'http_versions' => ['1.1', '2'],
                'retry_count' => 0,
                'maintenance' => null,
            ],
        ]);

        $this->actingAs($admin)
            ->get("/admin/domains/{$domain->id}")
            ->assertOk()
            ->assertSee('HTTP/1.1 + HTTP/2')
            ->assertSee('HTTPS redirect off');
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
