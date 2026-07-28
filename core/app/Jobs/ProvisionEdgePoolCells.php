<?php

namespace App\Jobs;

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
        // Mixed-version deployments may still contain this previously queued job.
        // Fail it closed so an upgrade can never create an implicit assignment.
        $operation = Operation::query()->findOrFail($this->operationId);
        if (in_array($operation->status, ['succeeded', 'failed'], true)) {
            return;
        }
        $operation->update([
            'status' => 'failed',
            'error' => 'automatic_cell_assignment_removed',
            'result' => ['pool_id' => $this->poolId, 'cells_provisioned' => 0],
            'started_at' => $operation->started_at ?? now(),
            'finished_at' => now(),
            'attempts' => $operation->attempts + 1,
        ]);
    }

    public function uniqueId(): string
    {
        return (string) $this->poolId;
    }
}
