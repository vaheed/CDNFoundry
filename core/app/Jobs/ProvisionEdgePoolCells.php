<?php

namespace App\Jobs;

use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionEdgePoolCells implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $poolId, public string $operationId)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function handle(): void
    {
        $pool = EdgePool::query()->findOrFail($this->poolId);
        $operation = Operation::query()->findOrFail($this->operationId);
        if ($operation->status === 'succeeded') {
            return;
        }
        $cursor = (string) ($operation->result['cursor'] ?? '');
        $provisioned = (int) ($operation->result['cells_provisioned'] ?? 0);
        $edges = Edge::query()->when($cursor !== '', fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id')->limit(250)->get();
        $operation->update(['status' => 'running', 'started_at' => $operation->started_at ?? now(), 'attempts' => $operation->attempts + 1]);
        foreach ($edges as $edge) {
            $edge->cells()->firstOrCreate(['edge_pool_id' => $pool->id], ['name' => $pool->name]);
            $provisioned++;
            $cursor = $edge->id;
        }
        $hasMore = $edges->count() === 250 && Edge::query()->where('id', '>', $cursor)->exists();
        $operation->update([
            'status' => $hasMore ? 'running' : 'succeeded',
            'result' => ['pool_id' => $pool->id, 'cursor' => $cursor, 'cells_provisioned' => $provisioned],
            'error' => null, 'finished_at' => $hasMore ? null : now(),
        ]);
        if ($hasMore) {
            self::dispatch($pool->id, $operation->id);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->poolId;
    }
}
