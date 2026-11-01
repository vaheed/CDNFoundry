<?php

namespace App\Console\Commands;

use App\Jobs\MaintainBackupRepository as MaintainBackupRepositoryJob;
use App\Models\Operation;
use App\Support\ResticBackupRepository;
use Illuminate\Console\Command;

class MaintainBackupRepository extends Command
{
    protected $signature = 'cdnf:backups:maintain {task : prune or check}';

    protected $description = 'Prune old backup snapshots (prune) or verify the backup repository (check)';

    public function handle(ResticBackupRepository $repository): int
    {
        $task = (string) $this->argument('task');
        if (! in_array($task, ['prune', 'check'], true)) {
            $this->error('The task must be prune or check.');

            return self::INVALID;
        }
        if (! $repository->configured()) {
            $this->error('Encrypted off-host backup repository is not configured.');

            return self::FAILURE;
        }
        $operation = Operation::query()->create(['type' => "backup.{$task}", 'status' => 'pending', 'input' => ['task' => $task]]);
        MaintainBackupRepositoryJob::dispatch($operation->id, $task);
        $this->line(json_encode(['operation_id' => $operation->id, 'task' => $task], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
