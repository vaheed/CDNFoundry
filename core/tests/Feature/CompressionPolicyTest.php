<?php

namespace Tests\Feature;

use App\Models\EdgePool;
use App\Models\User;
use App\Support\CompressionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CompressionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_profiles_are_bounded_and_maximum_savings_is_isolated(): void
    {
        $this->assertSame(32, CompressionPolicy::profile('standard')['maximum_active_requests']);
        $this->assertSame(16, CompressionPolicy::profile('maximum_savings')['maximum_active_requests']);
        $this->assertFalse(CompressionPolicy::profile('off')['gzip']);
        $this->assertFalse(CompressionPolicy::allowedForKind('maximum_savings', 'shared'));
        $this->assertTrue(CompressionPolicy::allowedForKind('maximum_savings', 'reserved'));
    }

    public function test_admin_api_rejects_unsafe_shared_profile_and_reconciles_valid_changes(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shared = EdgePool::query()->where('kind', 'shared')->firstOrFail();

        $this->actingAs($admin)->patchJson("/api/admin/edge-pools/{$shared->id}", [
            'compression_profile' => 'maximum_savings',
        ])->assertUnprocessable();
        $this->assertSame('standard', $shared->refresh()->compression_profile);

        $response = $this->actingAs($admin)->patchJson("/api/admin/edge-pools/{$shared->id}", [
            'compression_profile' => 'off',
        ])->assertAccepted()->assertJsonPath('data.pool.compression_profile', 'off');
        $this->assertNotNull($response->json('data.operation_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'edge.pool_updated', 'subject_id' => (string) $shared->id]);
    }

    public function test_database_defaults_existing_pools_to_standard(): void
    {
        $this->assertNotEmpty(EdgePool::query()->get());
        $this->assertTrue(EdgePool::query()->get()->every(
            fn (EdgePool $pool): bool => $pool->compression_profile === 'standard',
        ));
    }
}
