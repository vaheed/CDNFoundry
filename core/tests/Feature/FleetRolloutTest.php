<?php

namespace Tests\Feature;

use App\Jobs\AdvanceFleetRollout;
use App\Models\Edge;
use App\Models\FleetRelease;
use App\Models\FleetRollout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FleetRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_create_immutable_release(): void
    {
        $payload = $this->releasePayload('release-a');
        $this->actingAs(User::factory()->create())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/fleet/releases', $payload)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/fleet/releases', $payload)->assertCreated();
        $this->assertDatabaseHas('fleet_releases', ['name' => 'release-a']);
    }

    public function test_release_rejects_tags_and_invalid_compatibility(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->releasePayload('invalid');
        $payload['gateway_image'] = 'registry.test/gateway:latest';
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/fleet/releases', $payload)->assertUnprocessable()->assertJsonValidationErrors('versions.gateway');

        $payload = $this->releasePayload('invalid-range');
        $payload['minimum_compatible_version'] = '2.0.0';
        $payload['maximum_compatible_version'] = '1.0.0';
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/fleet/releases', $payload)->assertUnprocessable();
    }

    public function test_canary_is_dispatched_before_later_wave_with_bounded_parallelism(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $release = FleetRelease::query()->create($this->releasePayload('target'));
        $canary = $this->edge('canary');
        $later = $this->edge('later');
        $response = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/fleet/rollouts', [
                'fleet_release_id' => $release->id, 'canary_edge_ids' => [$canary->id],
                'edge_ids' => [$canary->id, $later->id], 'wave_size' => 1, 'maximum_parallel' => 1,
                'minimum_ready_percent' => 100, 'maximum_error_percent' => 5, 'mixed_version_window_minutes' => 60,
            ])->assertAccepted();
        $rollout = FleetRollout::query()->findOrFail($response->json('data.id'));

        (new AdvanceFleetRollout($rollout->id))->handle();

        $this->assertSame('dispatched', $rollout->edges()->where('edge_id', $canary->id)->value('status'));
        $this->assertSame('pending', $rollout->edges()->where('edge_id', $later->id)->value('status'));
        $this->assertDatabaseHas('edge_tasks', ['edge_id' => $canary->id, 'type' => 'runtime_upgrade', 'status' => 'pending']);
        $this->assertDatabaseMissing('edge_tasks', ['edge_id' => $later->id, 'type' => 'runtime_upgrade']);
    }

    public function test_unready_canary_pauses_without_dispatching_any_task(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $release = FleetRelease::query()->create($this->releasePayload('target'));
        $edge = $this->edge('unready');
        $edge->update(['last_heartbeat_at' => now()->subMinute()]);
        $response = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/fleet/rollouts', [
                'fleet_release_id' => $release->id, 'canary_edge_ids' => [$edge->id], 'edge_ids' => [$edge->id],
                'wave_size' => 1, 'maximum_parallel' => 1, 'minimum_ready_percent' => 100,
                'maximum_error_percent' => 5, 'mixed_version_window_minutes' => 60,
            ])->assertAccepted();

        (new AdvanceFleetRollout($response->json('data.id')))->handle();

        $this->assertDatabaseHas('fleet_rollouts', ['id' => $response->json('data.id'), 'status' => 'paused', 'pause_reason' => 'edge_not_ready']);
        $this->assertDatabaseCount('edge_tasks', 0);
    }

    private function edge(string $name): Edge
    {
        return Edge::query()->create([
            'name' => $name, 'country_code' => 'IR', 'continent_code' => 'AS',
            'enabled' => true, 'drained' => false, 'registered_at' => now(), 'last_heartbeat_at' => now(),
            'capacity' => ['listener_ready' => true, 'gateway' => ['ready' => true, 'active_revision' => 1]],
            'cell_slot_count' => 1,
        ]);
    }

    private function releasePayload(string $name): array
    {
        $digest = str_repeat('a', 64);

        return [
            'name' => $name, 'gateway_image' => "registry.test/gateway@sha256:$digest",
            'agent_image' => "registry.test/agent@sha256:$digest",
            'normal_cell_image' => "registry.test/cell@sha256:$digest",
            'waf_cell_image' => "registry.test/waf-cell@sha256:$digest",
            'minimum_compatible_version' => '1.0.0', 'maximum_compatible_version' => '1.99.0',
        ];
    }
}
