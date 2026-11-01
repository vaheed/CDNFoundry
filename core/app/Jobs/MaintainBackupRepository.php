<?php

namespace App\Jobs;

use App\Models\Operation;
use App\Support\ResticBackupRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Prunes old snapshots or verifies the repository; the result is an operation the health page reads. */
class MaintainBackupRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3700;

    /** @param 'prune'|'check' $task */
    public function __construct(public string $operationId, public string $task)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function uniqueId(): string
    {
        return "backup-repository-{$this->task}";
    }

    public function handle(ResticBackupRepository $repository): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        $operation->update(['status' => 'running', 'started_at' => now(), 'attempts' => $operation->attempts + 1]);
        $this->task === 'prune' ? $repository->prune() : $repository->check();
        $operation->update(['status' => 'succeeded', 'finished_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        Operation::query()->whereKey($this->operationId)->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
    }
}
