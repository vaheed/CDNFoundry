<?php

namespace Tests\Feature;

use App\Models\Edge;
use App\Models\EdgePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EdgeEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrolling_a_pop_creates_the_edge_and_joins_every_cell_to_the_shared_pool(): void
    {
        Queue::fake();

        $this->assertSame(0, Artisan::call('cdnf:edges:enroll', ['name' => 'pop-1', '--country' => 'de', '--continent' => 'eu']));
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $edge = Edge::query()->findOrFail($payload['edge_id']);
        $pool = EdgePool::query()->where('kind', 'shared')->firstOrFail();
        $this->assertSame('DE', $edge->country_code);
        $this->assertSame(64, strlen($payload['bootstrap_token']));
        $this->assertSame(hash('sha256', $payload['bootstrap_token']), $edge->bootstrap_token_hash);
        $this->assertSame($pool->id, $payload['pool_id']);
        $this->assertSame(Edge::CELL_SLOT_COUNT, $edge->cells()->where('edge_pool_id', $pool->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'edge.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'edge.pool_cell_assigned']);
    }

    public function test_enrollment_refuses_bad_codes_and_duplicate_names(): void
    {
        Queue::fake();

        $this->assertSame(1, Artisan::call('cdnf:edges:enroll', ['name' => 'pop-1', '--country' => 'XX', '--continent' => 'EU']));
        $this->assertSame(0, Artisan::call('cdnf:edges:enroll', ['name' => 'pop-1', '--country' => 'DE', '--continent' => 'EU']));
        $this->assertSame(1, Artisan::call('cdnf:edges:enroll', ['name' => 'pop-1', '--country' => 'DE', '--continent' => 'EU']));
        $this->assertSame(1, Edge::query()->count());
    }

    public function test_enrollment_refuses_when_no_shared_pool_exists(): void
    {
        Queue::fake();
        EdgePool::query()->where('kind', 'shared')->update(['enabled' => false]);

        $this->assertSame(1, Artisan::call('cdnf:edges:enroll', ['name' => 'pop-1', '--country' => 'DE', '--continent' => 'EU']));
        $this->assertSame(0, Edge::query()->count());
    }
}
