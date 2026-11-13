<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Shared\Pages\ApiTokens;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Support\Impersonation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_acts_as_a_domain_user_sees_the_banner_and_returns(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
        $user = User::factory()->create(['name' => 'Dana Domain']);
        $domain = Domain::query()->create(['name' => 'acting.example', 'display_name' => 'Acting', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)->assertTableActionVisible('impersonate', $user)->assertTableActionHidden('impersonate', $admin)
            ->callTableAction('impersonate', $user)->assertRedirect('/app');
        $this->assertTrue(auth()->user()->is($user));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.impersonation_started', 'subject_id' => $user->id]);

        $this->get('/app/domains')->assertOk()->assertSee('acting.example')
            ->assertSee('data-testid="impersonation-banner"', false)->assertSee('Dana Domain')->assertSee('made by Ada Admin');

        // A change made meanwhile is the user's, made by the administrator.
        AuditLog::record($user, 'domain.updated', $domain);
        $this->assertSame($admin->id, AuditLog::query()->where('action', 'domain.updated')->sole()->metadata['impersonated_by']);

        $this->post('/impersonation/leave')->assertRedirect('/admin/users');
        $this->assertTrue(auth()->user()->is($admin));
        $this->assertFalse(Impersonation::active());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.impersonation_ended', 'subject_id' => $user->id]);
        $this->get('/app/domains')->assertDontSee('data-testid="impersonation-banner"', false);
    }

    public function test_no_one_acts_as_an_administrator_a_disabled_user_or_themselves_and_users_cannot_start_it(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $disabled = User::factory()->create(['disabled_at' => now()]);
        $user = User::factory()->create();

        $this->assertFalse(Impersonation::allowed($admin, $other));
        $this->assertFalse(Impersonation::allowed($admin, $disabled));
        $this->assertFalse(Impersonation::allowed($admin, $admin));
        $this->assertFalse(Impersonation::allowed($user, User::factory()->create()));
        $this->assertTrue(Impersonation::allowed($admin, $user));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        Livewire::test(ListUsers::class)->assertTableActionHidden('impersonate', $other)->assertTableActionHidden('impersonate', $disabled);
    }

    public function test_leaving_without_acting_as_anyone_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->post('/impersonation/leave')->assertForbidden();
    }

    public function test_tokens_and_profile_changes_are_refused_while_acting_as_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        Livewire::test(ListUsers::class)->callTableAction('impersonate', $user);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(ApiTokens::class)
            ->set('name', 'acting')->set('ability', 'read')->set('lifetimeDays', 30)->call('createToken')->assertForbidden();
        $this->assertSame(0, $user->tokens()->count());
    }
}
