<?php

namespace App\Console\Commands;

use App\Support\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PublishServiceHealth extends Command
{
    protected $signature = 'cdnf:health:publish';

    protected $description = 'Publish a bounded public service health snapshot';

    public function handle(SystemHealth $health): int
    {
        $snapshot = $health->publicSnapshot($health->components(), $health->queues());
        Cache::put(SystemHealth::PUBLIC_CACHE_KEY, $snapshot, now()->addMinutes(10));

        return self::SUCCESS;
    }
}
