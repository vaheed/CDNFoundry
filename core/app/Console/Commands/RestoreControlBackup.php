<?php

namespace App\Console\Commands;

use App\Jobs\BuildUsageRollups;
use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileAllPurges;
use App\Jobs\ReconcileAllTls;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Operation;
use App\Support\ResticBackupRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RestoreControlBackup extends Command
{
    protected $signature = 'backups:restore {operation : Successful backup.restore preflight operation UUID}';

    protected $description = 'Restore a preflighted control backup while the deployment is in explicit maintenance mode';

    public function handle(ResticBackupRepository $repository): int
    {
        if (! filter_var(env('BACKUP_RESTORE_ALLOWED', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Set BACKUP_RESTORE_ALLOWED=true only in the one-off maintenance container.');

            return self::FAILURE;
        }
        $operation = Operation::query()->whereKey($this->argument('operation'))->where('type', 'backup.restore')->where('status', 'running')->first();
        if ($operation === null || ($operation->result['preflight'] ?? null) !== 'passed') {
            $this->error('A successful restore preflight operation is required.');

            return self::FAILURE;
        }
        $backup = Backup::query()->whereKey($operation->input['backup_id'])->where('status', 'succeeded')->firstOrFail();
        Artisan::call('down', ['--retry' => 60]);
        try {
            $repository->restore($backup->snapshot_id);
            Artisan::call('migrate', ['--force' => true]);
            $restoredBackup = Backup::query()->find($backup->id) ?? Backup::query()->create(['id' => $backup->id]);
            $restoredBackup->update(['status' => 'succeeded', 'snapshot_id' => $backup->snapshot_id, 'verified_at' => now(), 'last_error' => null]);
            $receipt = Operation::query()->find($operation->id) ?? Operation::query()->create(['id' => $operation->id, 'type' => 'backup.restore', 'status' => 'running', 'input' => ['backup_id' => $backup->id]]);
            $receipt->update(['status' => 'succeeded', 'result' => ['backup_id' => $backup->id, 'snapshot_id' => $backup->snapshot_id, 'restored_at' => now()->toIso8601String()], 'finished_at' => now()]);
            AuditLog::record(null, 'backup.restore_completed', $restoredBackup, ['operation_id' => $receipt->id, 'snapshot_id' => $backup->snapshot_id]);
            ReconcileAllDnsZones::dispatch(Operation::query()->create(['type' => 'dns.global_reconcile', 'status' => 'pending', 'input' => []])->id);
            ReconcileAllEdgeDomains::dispatch(Operation::query()->create(['type' => 'edges.global_reconcile', 'status' => 'pending', 'input' => []])->id);
            ReconcileAllTls::dispatch(Operation::query()->create(['type' => 'tls.global_reconcile', 'status' => 'pending', 'input' => []])->id);
            ReconcileAllPurges::dispatch(Operation::query()->create(['type' => 'purges.global_reconcile', 'status' => 'pending', 'input' => []])->id);
            $from = now()->utc()->subHour()->startOfHour();
            $to = now()->utc()->startOfHour();
            $usage = Operation::query()->create(['type' => 'usage.global_reconcile', 'status' => 'pending', 'input' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()]]);
            BuildUsageRollups::dispatch($from->toIso8601String(), $to->toIso8601String(), null, $usage->id);
            Artisan::call('up');
            $this->info('Restore completed; reconciliation has been queued.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            try {
                Operation::query()->whereKey($operation->id)->update([
                    'status' => 'failed',
                    'error' => mb_substr($exception->getMessage(), 0, 4000),
                    'finished_at' => now(),
                ]);
                AuditLog::record(null, 'backup.restore_failed', $backup, ['operation_id' => $operation->id]);
            } catch (Throwable) {
                // The restore may have failed while replacing PostgreSQL itself.
            }
            $this->error('Restore failed; keep maintenance mode active and inspect the recovery host logs.');
            report($exception);

            return self::FAILURE;
        }
    }
}
