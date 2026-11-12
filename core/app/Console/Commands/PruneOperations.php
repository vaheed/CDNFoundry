<?php

namespace App\Console\Commands;

use App\Models\Operation;
use App\Support\PlatformSettings;
use Illuminate\Console\Command;

class PruneOperations extends Command
{
    protected $signature = 'cdnf:operations:prune {--batch=5000}';

    protected $description = 'Delete one bounded batch of finished operations beyond the configured retention window';

    public function handle(PlatformSettings $settings): int
    {
        $batch = max(1, min(10000, (int) $this->option('batch')));
        $ids = Operation::query()->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subDays($settings->integer('operations', 'operation_retention_days')))
            ->where(fn ($query) => $query->where('status', '!=', 'failed')->orWhereNotNull('acknowledged_at'))
            ->orderBy('finished_at')->limit($batch)->pluck('id');
        $deleted = $ids->isEmpty() ? 0 : Operation::query()->whereIn('id', $ids)->delete();
        $this->info("Deleted {$deleted} finished operations.");

        return self::SUCCESS;
    }
}
