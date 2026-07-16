<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DomainApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_adds_domain_without_origin_and_only_sees_assigned_domains(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $created = $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/domains', ['name' => 'Example.COM.'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'example.com')
            ->assertJsonPath('data.lifecycle_state', 'pending_verification')
            ->assertJsonMissingPath('data.origin');

        $domain = Domain::findOrFail($created->json('data.id'));
        $this->assertTrue($domain->users()->whereKey($user->id)->exists());
        $this->actingAs($user)->getJson('/api/domains')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson("/api/domains/{$domain->id}")->assertForbidden();
        $this->actingAs($other)->getJson('/api/domains')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.created', 'subject_id' => (string) $domain->id]);
    }

    public function test_canonical_duplicates_punycode_collisions_and_public_suffixes_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/domains', ['name' => 'EXAMPLE.com'])->assertCreated();
        $this->actingAs($user)->postJson('/api/domains', ['name' => 'example.com.'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/domains', ['name' => 'bücher.de'])->assertCreated()->assertJsonPath('data.name', 'xn--bcher-kva.de');
        $this->actingAs($user)->postJson('/api/domains', ['name' => 'xn--bcher-kva.de'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/domains', ['name' => 'co.uk'])->assertUnprocessable();
    }

    public function test_admin_assigns_and_removes_domain_user_idempotently(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.net', 'display_name' => 'example.net']);

        $url = "/api/admin/domains/{$domain->id}/users";
        $this->actingAs($admin)->postJson($url, ['user_id' => $user->id])->assertCreated();
        $this->actingAs($admin)->postJson($url, ['user_id' => $user->id])->assertOk();
        $this->actingAs($admin)->getJson("/api/admin/users/{$user->id}/domains")->assertOk()->assertJsonPath('data.0.id', $domain->id);
        $this->assertDatabaseCount('domain_user', 1);
        $this->actingAs($admin)->deleteJson("$url/{$user->id}")->assertNoContent();
        $this->assertDatabaseCount('domain_user', 0);
    }

    public function test_disable_and_deprovision_are_idempotent_and_preserve_state(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.org', 'display_name' => 'example.org', 'lifecycle_state' => DomainLifecycleState::Active]);
        $domain->users()->attach($user);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/disable")->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/disable")->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}")->assertAccepted()->assertJsonPath('data.lifecycle_state', 'deprovisioning');
        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}")->assertAccepted()->assertJsonPath('data.revision', 3);
        $this->assertDatabaseHas('domains', ['id' => $domain->id, 'name' => 'example.org', 'revision' => 3]);
        $this->actingAs($user)->postJson('/api/domains', ['name' => 'example.org'])->assertUnprocessable();
    }
}
