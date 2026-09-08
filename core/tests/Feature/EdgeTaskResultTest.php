<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\Edge;
use App\Models\EdgeTask;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class EdgeTaskResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_failure_after_receipt_write_rolls_back_the_task(): void
    {
        [$edge, $task] = $this->task('cell_restart');
        $event = 'eloquent.updated: '.EdgeTask::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('injected after receipt write');
        });
        try {
            $this->report($edge, $task, ['status' => 'succeeded', 'result' => ['status' => 'completed']]);
            $this->fail('Expected receipt persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected after receipt write', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('pending', $task->refresh()->status);
        $this->assertSame(0, $task->attempts);
        $this->assertNull($task->result);
        $this->assertNull($task->finished_at);
    }

    public function test_duplicate_purge_failure_does_not_consume_another_retry_during_backoff(): void
    {
        [$edge, $task] = $this->task('cache_purge');
        $payload = ['status' => 'failed', 'result' => ['status' => 'failed', 'failure_reason' => 'cache_purge_control_failed']];
        $this->assertTrue($this->report($edge, $task, $payload)['data']['accepted']);
        $available = $task->refresh()->available_at;
        $this->assertSame(1, $task->attempts);
        $this->assertTrue($available->isFuture());
        $this->assertTrue($this->report($edge, $task, $payload)['data']['replayed']);
        $this->assertSame(1, $task->refresh()->attempts);
        $this->assertTrue($available->equalTo($task->available_at));
        $this->travel(6)->seconds();
        $this->report($edge, $task, $payload);
        $this->assertSame(2, $task->refresh()->attempts);
        $this->assertSame('pending', $task->status);
    }

    public function test_terminal_receipt_is_immutable_and_scoped_to_its_edge(): void
    {
        [$edge, $task] = $this->task('cell_restart');
        $this->report($edge, $task, ['status' => 'succeeded', 'result' => ['status' => 'completed']]);
        $this->assertTrue($this->report($edge, $task, ['status' => 'failed', 'result' => ['status' => 'failed']])['data']['replayed']);
        $this->assertSame('succeeded', $task->refresh()->status);
        $this->assertSame(1, $task->attempts);
        $other = Edge::query()->create(['name' => 'unrelated-reporter', 'country_code' => 'IR', 'continent_code' => 'AS']);
        $this->expectException(ModelNotFoundException::class);
        $this->report($other, $task, ['status' => 'succeeded', 'result' => ['status' => 'completed']]);
    }

    private function task(string $type): array
    {
        $edge = Edge::query()->create(['name' => 'task-reporter', 'country_code' => 'IR', 'continent_code' => 'AS']);
        $task = EdgeTask::query()->create(['edge_id' => $edge->id, 'type' => $type, 'status' => 'pending', 'payload' => []]);

        return [$edge, $task];
    }

    private function report(Edge $edge, EdgeTask $task, array $payload): array
    {
        // Authentication middleware is qualified separately. Here the trusted
        // edge attribute exercises the real controller's task ownership scope.
        $request = Request::create('/edge/v1/tasks/'.$task->id.'/result', 'POST', $payload);
        $request->attributes->set('edge', $edge);

        return app(EdgeAgentController::class)->taskResult($request, $task->id)->getData(true);
    }
}
