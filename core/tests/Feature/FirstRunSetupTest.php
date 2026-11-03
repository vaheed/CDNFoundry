<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\FirstRunSetup;
use App\Models\User;
use App\Support\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    public function test_wizard_has_a_step_per_settings_group_and_shows_every_default(): void
    {
        $this->admin();
        $groups = array_keys(app(PlatformSettings::class)->definitions());

        $page = $this->get('/admin/first-run-setup')->assertOk()->assertSee('Every setting has a default');
        foreach ($groups as $group) {
            $page->assertSee('first-run-step-'.str_replace('_', '-', $group), false);
        }
        $page->assertSee('Default:', false)->assertSee('Keep all defaults');
    }

    public function test_it_is_only_for_administrators(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/first-run-setup')->assertForbidden();
    }

    public function test_finishing_saves_the_values_records_completion_and_hides_the_entry(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->assertTrue(FirstRunSetup::shouldRegisterNavigation());
        $values = app(PlatformSettings::class)->values('display');

        Livewire::test(FirstRunSetup::class)
            ->fillForm(['display' => ['timezone' => 'Asia/Tehran']])
            ->call('finish')->assertHasNoFormErrors();

        $this->assertSame('Asia/Tehran', app(PlatformSettings::class)->get('display', 'timezone'));
        $this->assertNotSame('Asia/Tehran', $values['timezone']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'setup.completed', 'actor_id' => $admin->id]);
        $this->assertFalse(FirstRunSetup::shouldRegisterNavigation());
    }

    public function test_invalid_values_are_refused_and_nothing_is_marked_complete(): void
    {
        $this->admin();

        Livewire::test(FirstRunSetup::class)
            ->fillForm(['display' => ['timezone' => 'Not/AZone']])
            ->call('finish')->assertHasFormErrors();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'setup.completed']);
        $this->assertTrue(FirstRunSetup::shouldRegisterNavigation());
    }

    public function test_keeping_the_defaults_changes_nothing_and_records_the_choice(): void
    {
        $this->admin();
        $before = app(PlatformSettings::class)->values('display');

        Livewire::test(FirstRunSetup::class)->callAction('skipSetup')->assertHasNoActionErrors();

        $this->assertSame($before, app(PlatformSettings::class)->values('display'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'setup.completed']);
    }
}
