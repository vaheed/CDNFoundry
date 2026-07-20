<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\Operation;
use App\Support\ResticBackupRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeleteControlBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $backupId, public string $operationId)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function handle(ResticBackupRepository $repository): void
    {
        $backup = Backup::query()->findOrFail($this->backupId);
        $operation = Operation::query()->findOrFail($this->operationId);
        $operation->update(['status' => 'running', 'started_at' => now(), 'attempts' => $operation->attempts + 1]);
        if ($backup->snapshot_id) {
            $repository->forget($backup->snapshot_id);
        }
        $backup->delete();
        $operation->update(['status' => 'succeeded', 'result' => ['backup_id' => $this->backupId, 'deleted' => true], 'finished_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        Backup::query()->whereKey($this->backupId)->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 4000)]);
        Operation::query()->whereKey($this->operationId)->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
    }
}
