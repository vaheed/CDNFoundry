<?php

namespace Tests\Feature;

use App\Actions\PlanDomainEdgeCells;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgePool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiCellPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_pool_places_domains_stably_across_three_cells_without_replication(): void
    {
        [$edge, $pool] = $this->poolWithCells('shared', 3);
        $domain = Domain::query()->create(['name' => 'stable.example', 'display_name' => 'Stable', 'revision' => 7]);
        $placement = DomainEdgePlacement::query()->create([
            'domain_id' => $domain->id, 'target_pool_id' => $pool->id, 'desired_revision' => 7, 'state' => 'deploying',
        ]);

        PlanDomainEdgeCells::execute($domain, $placement, $pool);
        $first = DomainEdgeCell::query()->where('domain_id', $domain->id)->value('target_cell_id');
        PlanDomainEdgeCells::execute($domain, $placement, $pool);

        $this->assertSame($first, DomainEdgeCell::query()->where('domain_id', $domain->id)->value('target_cell_id'));
        $this->assertSame(1, DomainEdgeCell::query()->where('domain_id', $domain->id)->count());
    }

    public function test_replication_is_exceptional_bounded_and_uses_distinct_cells(): void
    {
        [$edge, $pool] = $this->poolWithCells('reserved', 3, ['replicas_per_edge' => 3]);
        $domain = Domain::query()->create(['name' => 'reserved.example', 'display_name' => 'Reserved', 'revision' => 1]);
        $placement = DomainEdgePlacement::query()->create([
            'domain_id' => $domain->id, 'target_pool_id' => $pool->id, 'desired_revision' => 1, 'state' => 'deploying',
        ]);

        PlanDomainEdgeCells::execute($domain, $placement, $pool);

        $rows = DomainEdgeCell::query()->where('domain_id', $domain->id)->orderBy('replica')->get();
        $this->assertSame([1, 2, 3], $rows->pluck('replica')->all());
        $this->assertCount(3, $rows->pluck('target_cell_id')->unique());
        $this->assertTrue($rows->every(fn ($row) => $row->edge_id === $edge->id));
    }

    public function test_pool_policy_and_explicit_cell_participation_are_admin_only_bounded_and_idempotent(): void
    {
        Queue::fake();
        [$edge, $pool] = $this->poolWithCells('reserved', 1);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $cell = $edge->cells()->whereNull('edge_pool_id')->firstOrFail();

        $this->actingAs($user)->putJson("/api/admin/edge-pools/{$pool->id}/cells/{$cell->id}")->assertForbidden();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->putJson("/api/admin/edge-pools/{$pool->id}/cells/{$cell->id}", [
            'service_ipv4' => '8.8.8.8',
        ])->assertUnprocessable()->assertJsonValidationErrors('service_ipv4');
        $key = (string) Str::uuid();
        $this->actingAs($admin)->withHeader('Idempotency-Key', $key)->putJson("/api/admin/edge-pools/{$pool->id}/cells/{$cell->id}")->assertAccepted();
        $this->actingAs($admin)->withHeader('Idempotency-Key', $key)->putJson("/api/admin/edge-pools/{$pool->id}/cells/{$cell->id}")->assertAccepted();
        $this->assertSame($pool->id, $cell->refresh()->edge_pool_id);

        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->patchJson("/api/admin/edge-pools/{$pool->id}", ['replicas_per_edge' => 4])->assertUnprocessable();
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->patchJson("/api/admin/edge-pools/{$shared->id}", ['replicas_per_edge' => 2])->assertUnprocessable();
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/edge-pools', [
            'name' => 'invalid-replicated-shared', 'kind' => 'shared', 'replicas_per_edge' => 2,
        ])->assertUnprocessable();
        $response = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/admin/edge-pools/{$pool->id}", ['replicas_per_edge' => 2])->assertAccepted();
        $this->assertDatabaseHas('operations', [
            'id' => $response->json('data.operation_id'), 'type' => 'edge.global_reconcile',
            'input' => json_encode(['pool_id' => $pool->id, 'reason' => 'pool_policy_changed']),
        ]);
        Queue::assertPushed(ReconcileAllEdgeDomains::class, fn (ReconcileAllEdgeDomains $job): bool => $job->operationId === $response->json('data.operation_id'));
    }

    public function test_capacity_failure_preserves_existing_active_cell(): void
    {
        [$edge, $source] = $this->poolWithCells('shared', 1);
        $target = EdgePool::query()->create(['name' => 'capacity-target', 'kind' => 'reserved', 'enabled' => true, 'maximum_domains_per_cell' => 1]);
        $targetCell = $edge->cells()->whereNull('edge_pool_id')->firstOrFail();
        $targetCell->update(['edge_pool_id' => $target->id, 'status' => 'assigned']);
        $target->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => '9.9.9.9']);
        $existing = Domain::query()->create(['name' => 'existing.example', 'display_name' => 'Existing', 'revision' => 1]);
        DomainEdgeCell::query()->create(['domain_id' => $existing->id, 'edge_id' => $edge->id, 'replica' => 1, 'active_cell_id' => $targetCell->id, 'desired_revision' => 1, 'state' => 'active']);
        $domain = Domain::query()->create(['name' => 'move.example', 'display_name' => 'Move', 'revision' => 2]);
        $sourceCell = $edge->cells()->where('edge_pool_id', $source->id)->firstOrFail();
        DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => 1, 'active_cell_id' => $sourceCell->id, 'desired_revision' => 1, 'state' => 'active']);
        $placement = DomainEdgePlacement::query()->create(['domain_id' => $domain->id, 'active_pool_id' => $source->id, 'target_pool_id' => $target->id, 'desired_revision' => 2, 'state' => 'deploying']);

        try {
            PlanDomainEdgeCells::execute($domain, $placement, $target);
            $this->fail('Expected bounded cell capacity failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('pool_cell_domain_capacity_exhausted', $exception->getMessage());
        }
        $this->assertDatabaseHas('domain_edge_cells', ['domain_id' => $domain->id, 'active_cell_id' => $sourceCell->id, 'target_cell_id' => null, 'state' => 'active']);
    }

    public function test_capacity_selection_skips_full_cell_and_preserves_stable_full_assignment(): void
    {
        [$edge, $pool] = $this->poolWithCells('reserved', 2, ['maximum_domains_per_cell' => 1]);
        $cells = $edge->cells()->where('edge_pool_id', $pool->id)->orderBy('slot')->get();
        $existing = Domain::query()->create(['name' => 'full.example', 'display_name' => 'Full', 'revision' => 1]);
        $existingPlacement = DomainEdgePlacement::query()->create(['domain_id' => $existing->id, 'target_pool_id' => $pool->id, 'desired_revision' => 1, 'state' => 'deploying']);
        DomainEdgeCell::query()->create(['domain_id' => $existing->id, 'edge_id' => $edge->id, 'replica' => 1, 'target_cell_id' => $cells[0]->id, 'desired_revision' => 1, 'state' => 'deploying']);
        PlanDomainEdgeCells::execute($existing, $existingPlacement, $pool);
        $this->assertDatabaseHas('domain_edge_cells', ['domain_id' => $existing->id, 'target_cell_id' => $cells[0]->id]);

        $new = Domain::query()->create(['name' => 'available.example', 'display_name' => 'Available', 'revision' => 1]);
        $newPlacement = DomainEdgePlacement::query()->create(['domain_id' => $new->id, 'target_pool_id' => $pool->id, 'desired_revision' => 1, 'state' => 'deploying']);
        PlanDomainEdgeCells::execute($new, $newPlacement, $pool);
        $this->assertDatabaseHas('domain_edge_cells', ['domain_id' => $new->id, 'target_cell_id' => $cells[1]->id]);
    }

    public function test_cell_removal_enforces_minimum_participation_per_edge(): void
    {
        Queue::fake();
        $pool = EdgePool::query()->create(['name' => 'minimum-per-edge', 'kind' => 'reserved', 'enabled' => true, 'minimum_ready_cells' => 2]);
        $admin = User::factory()->admin()->create();
        $first = Edge::query()->create(['name' => 'minimum-first', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.80']);
        $second = Edge::query()->create(['name' => 'minimum-second', 'country_code' => 'DE', 'continent_code' => 'EU', 'management_ipv4' => '203.0.113.90']);
        foreach ([[$first, 80], [$second, 90]] as [$edge, $base]) {
            for ($slot = 1; $slot <= 3; $slot++) {
                $edge->cells()->create(['slot' => $slot, 'edge_pool_id' => $pool->id, 'status' => 'assigned']);
            }
        }
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->deleteJson("/api/admin/edge-pools/{$pool->id}/cells/{$first->cells()->orderByDesc('slot')->value('id')}")->assertNoContent();
        $remainingCellId = $first->cells()->where('edge_pool_id', $pool->id)->orderByDesc('slot')->value('id');
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->deleteJson("/api/admin/edge-pools/{$pool->id}/cells/{$remainingCellId}")->assertConflict();
        $this->assertSame(2, $first->cells()->where('edge_pool_id', $pool->id)->count());
        $this->assertSame(3, $second->cells()->where('edge_pool_id', $pool->id)->count());
    }

    public function test_twenty_thousand_domain_scale_and_change_burst_do_not_reshuffle(): void
    {
        $edge = '019fa7b9-0000-7000-8000-000000000001';
        $before = [];
        $started = hrtime(true);
        for ($domain = 1; $domain <= 20000; $domain++) {
            $before[$domain] = PlanDomainEdgeCells::selectStableCellId(null, $domain, $edge, 1, [1, 2, 3]);
        }
        for ($domain = 1; $domain <= 10000; $domain++) {
            $this->assertSame($before[$domain], PlanDomainEdgeCells::selectStableCellId($before[$domain], $domain, $edge, 1, [1, 2, 3, 4]));
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertCount(20000, $before);
        $this->assertLessThan(5.0, $elapsed, "Placement scale qualification took {$elapsed}s");
    }

    public function test_source_only_edge_receives_retirement_tombstone_after_target_drain(): void
    {
        Queue::fake();
        $source = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $target = EdgePool::query()->create(['name' => 'partial-target', 'kind' => 'reserved', 'enabled' => true]);
        $first = Edge::query()->create(['name' => 'target-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.40']);
        $second = Edge::query()->create(['name' => 'source-only-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'management_ipv4' => '203.0.113.50']);
        foreach ([$first, $second] as $edge) {
            $edge->cells()->create(['slot' => 1, 'edge_pool_id' => $source->id, 'status' => 'assigned']);
            $source->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => $edge->is($first) ? '8.8.8.8' : '8.8.4.4']);
        }
        $targetCell = $first->cells()->create(['slot' => 2, 'edge_pool_id' => $target->id, 'status' => 'assigned']);
        $target->endpoints()->create(['edge_id' => $first->id, 'ipv4' => '9.9.9.9']);
        $domain = Domain::query()->create(['name' => 'retire.example', 'display_name' => 'Retire', 'revision' => 2, 'lifecycle_state' => 'active']);
        $domain->dnsRecords()->create([
            'type' => 'A', 'name' => 'www.retire.example', 'content' => '8.8.8.8', 'ttl' => 60, 'mode' => 'proxied',
            'content_hash' => hash('sha256', '8.8.8.8'),
            'origin' => ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 1],
        ]);
        $placement = DomainEdgePlacement::query()->create(['domain_id' => $domain->id, 'active_pool_id' => $source->id, 'target_pool_id' => $target->id, 'desired_revision' => 2, 'state' => 'deploying']);
        foreach ([$first, $second] as $edge) {
            DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => 1, 'active_cell_id' => $edge->cells()->where('edge_pool_id', $source->id)->value('id'), 'desired_revision' => 1, 'state' => 'active']);
        }
        EdgeArtifact::query()->create(['edge_id' => $second->id, 'domain_id' => $domain->id, 'kind' => 'domain', 'revision' => 1, 'payload' => ['domain' => $domain->name], 'checksum' => str_repeat('a', 64), 'signature' => str_repeat('b', 128)]);

        PlanDomainEdgeCells::execute($domain, $placement, $target);
        $this->assertDatabaseHas('domain_edge_cells', ['domain_id' => $domain->id, 'edge_id' => $second->id, 'target_cell_id' => null, 'desired_revision' => 2, 'state' => 'deploying']);
        DomainEdgeCell::query()->where('domain_id', $domain->id)->update(['state' => 'draining']);
        $placement->update(['state' => 'draining', 'drain_after' => now()->subSecond()]);
        $this->artisan('edge:complete-placement-drains')->assertSuccessful();
        $this->assertDatabaseMissing('domain_edge_cells', ['domain_id' => $domain->id, 'edge_id' => $second->id]);
        $this->assertDatabaseHas('domain_edge_cells', ['domain_id' => $domain->id, 'edge_id' => $first->id, 'active_cell_id' => $targetCell->id, 'state' => 'deploying']);

        (new ReconcileEdgeDomain($domain->id))->handle();
        $this->assertDatabaseHas('edge_artifacts', ['edge_id' => $second->id, 'domain_id' => $domain->id, 'revision' => 3, 'kind' => 'tombstone']);
    }

    public function test_stale_deploying_placement_is_requeued_for_self_healing(): void
    {
        Queue::fake();
        $domain = Domain::query()->create(['name' => 'stale-placement.example', 'display_name' => 'Stale', 'revision' => 4]);
        $domain->dnsRecords()->create([
            'type' => 'A', 'name' => 'www.stale-placement.example', 'content' => '8.8.8.8', 'ttl' => 60, 'mode' => 'proxied',
            'content_hash' => hash('sha256', '8.8.8.8'),
            'origin' => ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'origin.example', 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 1],
        ]);
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $placement = DomainEdgePlacement::query()->create([
            'domain_id' => $domain->id, 'active_pool_id' => $pool->id, 'target_pool_id' => $pool->id,
            'desired_revision' => 4, 'state' => 'deploying',
        ]);
        DomainEdgePlacement::query()->whereKey($placement->id)->update(['updated_at' => now()->subMinutes(6)]);

        $this->artisan('edge:reconcile-stale-placements')->expectsOutput('Queued 1 stale edge placement(s).')->assertSuccessful();

        $this->assertDatabaseHas('operations', ['type' => 'edge.domain_reconcile', 'status' => 'pending']);
        Queue::assertPushed(ReconcileEdgeDomain::class, fn (ReconcileEdgeDomain $job): bool => $job->domainId === $domain->id);
    }

    private function poolWithCells(string $kind, int $count, array $policy = []): array
    {
        $pool = $kind === 'shared' ? EdgePool::query()->where('kind', 'shared')->firstOrFail()
            : EdgePool::query()->create(['name' => $kind.'-test', 'kind' => $kind, 'enabled' => true, ...$policy]);
        $edge = Edge::query()->create(['name' => $kind.'-edge', 'country_code' => 'IR', 'continent_code' => 'AS', 'management_ipv4' => '203.0.113.10', 'cell_slot_count' => 8]);
        for ($slot = 1; $slot <= 8; $slot++) {
            $edge->cells()->create([
                'slot' => $slot, 'edge_pool_id' => $slot <= $count ? $pool->id : null,
                'status' => $slot <= $count ? 'assigned' : 'unassigned',
            ]);
        }
        $pool->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => '8.8.8.8']);

        return [$edge, $pool];
    }
}
