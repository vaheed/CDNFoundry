<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\Operation;
use App\Support\ResticBackupRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PreflightBackupRestore implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $backupId, public string $operationId)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function handle(ResticBackupRepository $repository): void
    {
        $backup = Backup::query()->whereKey($this->backupId)->where('status', 'succeeded')->firstOrFail();
        $operation = Operation::query()->findOrFail($this->operationId);
        $operation->update(['status' => 'running', 'started_at' => now(), 'attempts' => $operation->attempts + 1]);
        $repository->snapshotExists($backup->snapshot_id);
        $operation->update(['status' => 'running', 'result' => ['backup_id' => $backup->id, 'snapshot_id' => $backup->snapshot_id, 'preflight' => 'passed', 'maintenance_command' => "php artisan backups:restore {$operation->id}"]]);
    }

    public function failed(Throwable $exception): void
    {
        Operation::query()->whereKey($this->operationId)->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
    }
}
