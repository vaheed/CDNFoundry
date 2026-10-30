<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckSchedulerLiveness extends Command
{
    protected $signature = 'cdnf:scheduler:check {--max-age=150}';

    protected $description = 'Fail unless the scheduler recorded its heartbeat recently (container health check)';

    public function handle(): int
    {
        $heartbeat = Cache::get('operations:scheduler_heartbeat');
        $age = $heartbeat === null ? null : CarbonImmutable::parse($heartbeat)->diffInSeconds(now(), false);
        if ($age === null || $age > (int) $this->option('max-age')) {
            $this->error('The scheduler heartbeat is missing or stale.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
