<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Telemetry;
use App\Filament\Domain\Widgets\AnalyticsSummary;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTelemetryAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pickers_list_verified_domains_only_so_a_shared_name_is_never_ambiguous(): void
    {
        $admin = User::factory()->admin()->create();
        $verified = Domain::query()->create(['name' => 'shared.example.test', 'display_name' => 'Real', 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $pending = Domain::query()->create(['name' => 'shared.example.test', 'display_name' => 'Claim', 'lifecycle_state' => 'pending_verification']);
        $onlyPending = Domain::query()->create(['name' => 'unclaimed.example.test', 'display_name' => 'Claim', 'lifecycle_state' => 'pending_verification']);

        $options = Livewire::actingAs($admin)->test(Telemetry::class)->instance()->filterOptions['domains'];

        $this->assertSame([$verified->id => 'shared.example.test'], $options);
        $this->assertArrayNotHasKey($pending->id, $options);
        $this->assertArrayNotHasKey($onlyPending->id, $options);
        $this->assertSame([$verified->id], Domain::query()->verified()->pluck('id')->all());
    }

    public function test_the_admin_telemetry_page_shows_the_analytics_domain_users_see(): void
    {
        $admin = User::factory()->admin()->create();
        Domain::query()->create(['name' => 'served.example.test', 'display_name' => 'Served', 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        Domain::query()->create(['name' => 'second.example.test', 'display_name' => 'Second', 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        Http::fake([config('services.clickhouse.url').'*' => Http::response('', 200)]);

        $this->actingAs($admin)->get('/admin/telemetry')->assertOk()
            ->assertSee('Requests')->assertSee('Bandwidth')->assertSee('Cache hit ratio')
            ->assertSee('All 2 domains');
    }

    public function test_the_platform_scope_is_for_administrators_only(): void
    {
        $user = User::factory()->create();
        $own = Domain::query()->create(['name' => 'mine.example.test', 'display_name' => 'Mine', 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $own->users()->attach($user->id);
        Domain::query()->create(['name' => 'foreign.example.test', 'display_name' => 'Foreign', 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        Http::fake([config('services.clickhouse.url').'*' => Http::response('', 200)]);

        // The same request from a domain user is still limited to the domain assigned to them.
        Livewire::actingAs($user)->test(AnalyticsSummary::class, ['pageFilters' => ['platform' => true, 'range' => '24h']])
            ->assertSee('mine.example.test')->assertDontSee('All 2 domains');

        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(AnalyticsSummary::class, ['pageFilters' => ['platform' => true, 'range' => '24h']])
            ->assertSee('All 2 domains');
    }
}
