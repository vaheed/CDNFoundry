<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\IpListsRelationManager;
use App\Models\Domain;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class IpListsPanelTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): array
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'lists-ui.example', 'display_name' => 'Lists', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $this->actingAs($user);

        return [$domain, Livewire::test(IpListsRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])];
    }

    public function test_a_list_is_added_from_lines_and_a_bad_line_is_named(): void
    {
        [$domain, $manager] = $this->manager();

        $manager->callTableAction('create', data: ['name' => 'staging', 'entries' => "203.0.113.7\nnot-an-address"])->assertHasTableActionErrors(['entries']);
        $this->assertSame(0, $domain->ipLists()->count());

        $manager = Livewire::test(IpListsRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class]);
        $manager->callTableAction('create', data: ['name' => 'staging', 'entries' => "203.0.113.7\n2001:db8::/48 203.0.113.7"])->assertHasNoTableActionErrors();
        $this->assertSame(['2001:db8::/48', '203.0.113.7/32'], $domain->ipLists()->firstOrFail()->entries);
    }

    public function test_a_list_that_a_rule_uses_cannot_be_deleted_from_the_panel(): void
    {
        [$domain, $manager] = $this->manager();
        $manager->callTableAction('create', data: ['name' => 'office', 'entries' => '203.0.113.0/24']);
        $domain->securityRules()->create(['action' => 'block', 'priority' => 1, 'conditions' => [['field' => 'client_ip_list', 'operator' => 'equals', 'value' => 'office']], 'fingerprint' => 'x']);

        $manager->callTableAction('delete', $domain->ipLists()->firstOrFail());

        $this->assertSame(1, $domain->ipLists()->count());
    }
}
