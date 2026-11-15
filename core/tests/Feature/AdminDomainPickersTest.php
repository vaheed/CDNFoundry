<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Telemetry;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDomainPickersTest extends TestCase
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
}
