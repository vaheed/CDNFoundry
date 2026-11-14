<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\LogPushRelationManager;
use App\Models\Domain;
use App\Models\LogPushDestination;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LogPushPanelTest extends TestCase
{
    use RefreshDatabase;

    private function switchOn(bool $on): void
    {
        $row = SystemSetting::query()->findOrFail('log_push');
        $row->update(['values' => [...$row->values, 'domain_user_log_push' => $on]]);
        app()->forgetInstance(PlatformSettings::class);
    }

    public function test_the_tab_follows_the_switch_and_a_user_adds_and_removes_a_destination(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'panel-push.example', 'display_name' => 'Panel', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $this->actingAs($user);
        $this->assertFalse(LogPushRelationManager::canViewForRecord($domain, ViewDomain::class));

        $this->switchOn(true);
        $this->assertTrue(LogPushRelationManager::canViewForRecord($domain, ViewDomain::class));
        Livewire::test(LogPushRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])
            ->callTableAction('addDestination', data: ['name' => 'SIEM', 'kind' => 'https', 'datasets' => ['http_requests'], 'url' => 'https://93.184.216.34/ingest', 'auth' => 'bearer', 'bearer_token' => 'token-1234567890'])
            ->assertHasNoTableActionErrors()->assertSee('SIEM');
        $destination = LogPushDestination::query()->firstOrFail();
        $this->assertSame($domain->id, $destination->domain_id);

        // A refused address is reported on its field, and nothing is saved.
        Livewire::test(LogPushRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])
            ->callTableAction('addDestination', data: ['name' => 'Loopback', 'kind' => 'https', 'datasets' => ['http_requests'], 'url' => 'https://127.0.0.1/ingest', 'auth' => 'hmac'])
            ->assertHasTableActionErrors(['url']);
        $this->assertSame(1, LogPushDestination::query()->count());

        // Switching off keeps the destination and the tab, and hides the actions that would start work.
        $this->switchOn(false);
        $this->assertTrue(LogPushRelationManager::canViewForRecord($domain, ViewDomain::class));
        Livewire::test(LogPushRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])->assertTableActionHidden('addDestination');
        Livewire::test(LogPushRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])->callTableAction('delete', $destination);
        $this->assertSame(0, LogPushDestination::query()->count());
    }
}
