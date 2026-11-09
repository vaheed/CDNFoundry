<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\Pages\ListDomains;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\Widgets\DomainNeedsAttention;
use App\Models\Domain;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DomainMonitoringPanelTest extends TestCase
{
    use RefreshDatabase;

    private function domain(string $name, array $attributes = []): Domain
    {
        $domain = new Domain;
        $domain->forceFill($attributes + [
            'name' => $name, 'display_name' => $name, 'revision' => 1,
            'lifecycle_state' => DomainLifecycleState::Active, 'nameservers_verified_at' => now()->subDay(),
            'delegation_nameservers' => ['ns1.cdn.example.net', 'ns2.cdn.example.net'],
        ])->save();

        return $domain;
    }

    public function test_panels_show_the_expiry_with_a_tone_and_the_lost_delegation_notice(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());
        $soon = $this->domain('soon.example.com', ['registration_expires_at' => now()->addDays(12)]);
        $this->domain('far.example.com', ['registration_expires_at' => now()->addDays(400)]);
        $this->domain('unknown.example.com');
        $lost = $this->domain('lost.example.com', ['lifecycle_state' => DomainLifecycleState::PendingVerification, 'delegation_lost_at' => now()->subHour(), 'ns_check_result' => 'mismatch', 'ns_observed' => ['ns1.other.example.org']]);

        $this->assertSame('warning', $soon->registrationExpiry()['tone']);
        $this->assertSame('danger', $this->domain('week.example.com', ['registration_expires_at' => now()->addDays(5)])->registrationExpiry()['tone']);
        $this->assertSame('danger', $this->domain('gone.example.com', ['registration_expires_at' => now()->subDays(3)])->registrationExpiry()['tone']);
        $this->assertSame('neutral', Domain::query()->where('name', 'far.example.com')->first()->registrationExpiry()['tone']);

        Livewire::test(ListDomains::class)->assertSee('Expires')->assertSee('In 12 days')->assertSee('Unknown');
        Livewire::test(ViewDomain::class, ['record' => $lost->id])
            ->assertSee('Seen at registrar')->assertSee('ns1.other.example.org')->assertSee('Registration expires');
        Livewire::test(DomainNeedsAttention::class, ['record' => $lost])->assertSee('Nameservers changed, so the domain is unverified');
        Livewire::test(DomainNeedsAttention::class, ['record' => $soon])->assertSee('Registration expires soon');
    }

    public function test_the_api_exposes_the_check_state_and_the_expiry(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain('api.example.com', ['registration_expires_at' => now()->addDays(40), 'ns_check_result' => 'matched', 'ns_checked_at' => now()]);
        $domain->users()->attach($user);

        $this->actingAs($user)->getJson("/api/domains/{$domain->id}")->assertOk()
            ->assertJsonPath('data.nameserver_check.result', 'matched')
            ->assertJsonPath('data.registration_expires_at', $domain->refresh()->registration_expires_at->toIso8601String());
    }
}
