<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\PlatformSettings;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune {--batch=1000}';

    protected $description = 'Delete one bounded batch of audit events beyond the configured retention window';

    public function handle(PlatformSettings $settings): int
    {
        $batch = max(1, min(10000, (int) $this->option('batch')));
        $ids = AuditLog::query()->where('created_at', '<', now()->subDays($settings->integer('operations', 'audit_retention_days')))->orderBy('id')->limit($batch)->pluck('id');
        $deleted = $ids->isEmpty() ? 0 : AuditLog::query()->whereIn('id', $ids)->delete();
        $this->info("Deleted {$deleted} expired audit events.");

        return self::SUCCESS;
    }
}
