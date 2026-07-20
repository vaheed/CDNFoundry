<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\Operation;
use App\Support\ResticBackupRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateControlBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 3700;

    public function __construct(public string $backupId, public string $operationId)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function uniqueId(): string
    {
        return $this->backupId;
    }

    public function handle(ResticBackupRepository $repository): void
    {
        $backup = Backup::query()->findOrFail($this->backupId);
        $operation = Operation::query()->findOrFail($this->operationId);
        if ($backup->status === 'succeeded') {
            return;
        }
        $backup->update(['status' => 'running', 'last_error' => null]);
        $operation->update(['status' => 'running', 'started_at' => $operation->started_at ?? now(), 'attempts' => $operation->attempts + 1]);
        $result = $repository->create();
        $backup->update([...$result, 'status' => 'succeeded', 'verified_at' => now()]);
        $operation->update(['status' => 'succeeded', 'result' => ['backup_id' => $backup->id, ...$result], 'finished_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        $error = mb_substr($exception->getMessage(), 0, 4000);
        Backup::query()->whereKey($this->backupId)->update(['status' => 'failed', 'last_error' => $error]);
        Operation::query()->whereKey($this->operationId)->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
    }
}
