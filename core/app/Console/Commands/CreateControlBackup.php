<?php

namespace App\Console\Commands;

use App\Jobs\CreateControlBackup as CreateControlBackupJob;
use App\Models\Backup;
use App\Models\Operation;
use App\Support\ResticBackupRepository;
use Illuminate\Console\Command;

class CreateControlBackup extends Command
{
    protected $signature = 'backups:create {--wait : Run in this process and wait for repository acknowledgement}';

    protected $description = 'Create an encrypted control PostgreSQL backup in the configured Restic repository';

    public function handle(ResticBackupRepository $repository): int
    {
        if (! $repository->configured()) {
            $this->error('Encrypted off-host backup repository is not configured.');

            return self::FAILURE;
        }
        $backup = Backup::query()->create(['status' => 'pending']);
        $operation = Operation::query()->create(['type' => 'backup.create', 'status' => 'pending', 'input' => ['backup_id' => $backup->id]]);
        if ($this->option('wait')) {
            CreateControlBackupJob::dispatchSync($backup->id, $operation->id);
        } else {
            CreateControlBackupJob::dispatch($backup->id, $operation->id);
        }
        $backup->refresh();
        $this->line(json_encode(['backup_id' => $backup->id, 'operation_id' => $operation->id, 'status' => $backup->status, 'snapshot_id' => $backup->snapshot_id, 'verified_at' => $backup->verified_at?->toIso8601String()], JSON_THROW_ON_ERROR));

        return $backup->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
