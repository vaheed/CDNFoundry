<?php

namespace Tests\Feature;

use App\Models\CachePurge;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeRevision;
use App\Models\EdgeTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'https://example.test/app.css?b=2&a=1', 'http://example.test/logo.png', 'https://www.example.test/site.css',
        ]])->assertAccepted();
        $purge = CachePurge::query()->findOrFail($urls->json('data.id'));
        $this->assertSame(['https|example.test|/app.css?b=2&a=1', 'http|example.test|/logo.png', 'https|www.example.test|/site.css'], $purge->cache_keys);
        $this->assertSame(2, $domain->refresh()->cache_epoch);

        $task = EdgeTask::query()->where('cache_purge_id', $purge->id)->firstOrFail();
        $task->update(['status' => 'succeeded', 'result' => ['status' => 'completed'], 'finished_at' => now()]);
        $purge->update(['status' => 'succeeded']);
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/cache/purges/{$purge->id}")
            ->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.edges.0.edge_id', $edge->id);
    }

    public function test_failed_edge_purge_retries_with_a_bound_and_same_task(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $edge = Edge::query()->create([
            'name' => 'retry-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'ipv4' => '203.0.113.11',
            'enabled' => true, 'registered_at' => now(), 'identity_certificate_serial' => 'ABCD1234',
            'identity_certificate_expires_at' => now()->addDay(),
        ]);
        $purgeId = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/cache/purge", ['type' => 'all'])->assertAccepted()->json('data.id');
        $task = EdgeTask::query()->where('cache_purge_id', $purgeId)->firstOrFail();
        $headers = ['X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => 'ABCD1234'];

        $this->withHeaders($headers)->postJson("/edge/v1/tasks/{$task->id}/result", ['status' => 'failed', 'result' => ['status' => 'failed', 'failure_reason' => 'cache_purge_control_failed']])->assertOk();
        $this->assertSame('pending', $task->refresh()->status);
        $this->assertSame(1, $task->attempts);
        $this->assertTrue($task->available_at->isFuture());
        $this->withHeaders($headers)->getJson('/edge/v1/tasks')->assertOk()->assertJsonCount(0, 'data');

        $task->update(['attempts' => 4, 'available_at' => now()->subSecond()]);
        $this->withHeaders($headers)->postJson("/edge/v1/tasks/{$task->id}/result", ['status' => 'failed', 'result' => ['status' => 'failed', 'failure_reason' => 'cache_purge_control_failed']])->assertOk();
        $this->assertSame('failed', $task->refresh()->status);
        $this->assertSame(5, $task->attempts);
        $this->assertSame('failed', CachePurge::query()->findOrFail($purgeId)->status);
        $this->assertSame($edge->id, $task->edge_id);
    }

    public function test_outstanding_purge_backlog_is_bounded_per_domain(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $now = now();
        foreach (range(1, 10) as $chunk) {
            DB::table('cache_purges')->insert(collect(range(1, 100))->map(fn (): array => [
                'id' => (string) Str::uuid(), 'domain_id' => $domain->id, 'requested_by' => $user->id,
                'type' => 'all', 'cache_epoch' => 1, 'cache_keys' => null, 'status' => 'running',
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/cache/purge", ['type' => 'all'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame(1000, CachePurge::query()->where('domain_id', $domain->id)->count());
        $this->assertSame(1, $domain->refresh()->cache_epoch);
    }

    public function test_cache_settings_roll_back_as_a_new_epoch_and_revision(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $prior = EdgeRevision::query()->create([
            'domain_id' => $domain->id, 'revision' => 1, 'checksum' => str_repeat('a', 64), 'status' => 'validated',
            'snapshot' => ['settings' => null, 'hostnames' => [], 'cache' => [...$this->settings(), 'edge_ttl_seconds' => 900, 'epoch' => 1, 'development_mode_until' => null]],
        ]);
        $domain->update(['revision' => 2, 'cache_epoch' => 4, 'cache_settings' => [...$this->settings(), 'edge_ttl_seconds' => 30]]);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/rollback", ['revision' => $prior->revision])->assertAccepted();
        $domain->refresh();
        $this->assertSame(900, $domain->cache_settings['edge_ttl_seconds']);
        $this->assertSame(5, $domain->cache_epoch);
        $this->assertSame(3, $domain->revision);
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
