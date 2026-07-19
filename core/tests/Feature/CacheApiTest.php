<?php

namespace Tests\Feature;

use App\Models\CachePurge;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CacheApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_settings_are_bounded_authorized_and_revisioned(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/cache")->assertForbidden();
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/cache")
            ->assertOk()->assertJsonPath('data.settings.edge_ttl_seconds', 3600)->assertJsonPath('data.cache_epoch', 1);
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/cache", [...$this->settings(), 'bypass_cookie_names' => array_fill(0, 33, 'cookie')])
            ->assertUnprocessable()->assertJsonValidationErrors('bypass_cookie_names');
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/cache", $this->settings())
            ->assertAccepted()->assertJsonPath('data.settings.include_query_string', true);

        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cache.settings_updated', 'subject_id' => (string) $domain->id]);
    }

    public function test_development_mode_has_a_bounded_visible_expiry(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/cache/development-mode", ['duration_minutes' => 0])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/cache/development-mode", ['duration_minutes' => 30])
            ->assertAccepted()->assertJsonPath('data.development_mode_until', fn ($value): bool => is_string($value));
        $this->assertTrue($domain->refresh()->cache_development_mode_until->isFuture());
        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}/cache/development-mode")->assertAccepted()
            ->assertJsonPath('data.development_mode_until', null);
    }

    public function test_full_and_url_purges_are_bounded_and_delivered_per_edge(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $domain->update(['cache_settings' => $this->settings()]);
        $edge = Edge::query()->create(['name' => 'cache-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'ipv4' => '203.0.113.10', 'enabled' => true, 'registered_at' => now()]);

        $full = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/cache/purge", ['type' => 'all'])
            ->assertAccepted()->assertJsonPath('data.cache_epoch', 2);
        $this->assertSame(2, $domain->refresh()->cache_epoch);
        $this->assertDatabaseHas('edge_tasks', ['edge_id' => $edge->id, 'cache_purge_id' => $full->json('data.id'), 'type' => 'cache_purge', 'status' => 'pending']);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/cache/purge", ['type' => 'urls', 'urls' => ['https://other.example/app.css']])
            ->assertUnprocessable()->assertJsonValidationErrors('urls');
        $urls = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/cache/purge", ['type' => 'urls', 'urls' => [
            'https://example.test/app.css?b=2&a=1', 'http://example.test/logo.png',
        ]])->assertAccepted();
        $purge = CachePurge::query()->findOrFail($urls->json('data.id'));
        $this->assertSame(['https|example.test|/app.css?b=2&a=1', 'http|example.test|/logo.png'], $purge->cache_keys);
        $this->assertSame(2, $domain->refresh()->cache_epoch);

        $task = EdgeTask::query()->where('cache_purge_id', $purge->id)->firstOrFail();
        $task->update(['status' => 'succeeded', 'result' => ['status' => 'completed'], 'finished_at' => now()]);
        $purge->update(['status' => 'succeeded']);
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/cache/purges/{$purge->id}")
            ->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.edges.0.edge_id', $edge->id);
    }

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.test', 'display_name' => 'Example', 'revision' => 1]);
        $domain->users()->attach($user);

        return [$user, $domain];
    }

    private function settings(): array
    {
        return ['enabled' => true, 'edge_ttl_seconds' => 120, 'browser_ttl_seconds' => 60, 'maximum_object_bytes' => 1048576, 'respect_origin_headers' => true, 'include_query_string' => true, 'bypass_cookie_names' => ['session_id'], 'stale_if_error_seconds' => 30];
    }
}
