<?php

namespace App\Console\Commands;

use App\Models\SecurityEvent;
use App\Support\PlatformSettings;
use Illuminate\Console\Command;

class PruneSecurityEvents extends Command
{
    protected $signature = 'cdnf:security-events:prune {--batch=5000}';

    protected $description = 'Delete one bounded batch of security events beyond the configured retention window';

    public function handle(PlatformSettings $settings): int
    {
        $batch = max(1, min(10000, (int) $this->option('batch')));
        $ids = SecurityEvent::query()->where('occurred_at', '<', now()->subDays($settings->integer('operations', 'security_event_retention_days')))
            ->orderBy('id')->limit($batch)->pluck('id');
        $deleted = $ids->isEmpty() ? 0 : SecurityEvent::query()->whereIn('id', $ids)->delete();
        $this->info("Deleted {$deleted} expired security events.");

        return self::SUCCESS;
    }
}
