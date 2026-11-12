<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\Widgets\DomainIncidentBanner;
use App\Models\Domain;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class IncidentProfilePanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_responder_starts_and_ends_the_profile_and_the_banner_tells_the_truth_about_it(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->admin()->create(['name' => 'Responder One']);
        $domain = Domain::query()->create(['name' => 'panel-incident.example', 'display_name' => 'Panel', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $this->actingAs($user);

        Livewire::test(DomainIncidentBanner::class, ['record' => $domain])->assertDontSee('Incident profile is on');
        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->assertActionVisible('startIncident')->assertActionHidden('endIncident')
            ->callAction('startIncident', ['duration_minutes' => 30])->assertHasNoActionErrors();
        $domain->refresh();
        $this->assertNotNull($domain->incident_expires_at);

        Livewire::test(DomainIncidentBanner::class, ['record' => $domain])
            ->assertSee('Incident profile is on')->assertSee('Responder One')
            ->assertSee('Protected request and connection limits')->assertDontSee('Not enforced yet')
            ->assertSee('cannot absorb traffic that saturates a host, a link, or an upstream');
        Livewire::test(ViewDomain::class, ['record' => $domain->id])
            ->assertActionHidden('startIncident')->assertActionVisible('endIncident')
            ->callAction('endIncident')->assertHasNoActionErrors();
        $this->assertNull($domain->refresh()->incident_expires_at);
    }
}
