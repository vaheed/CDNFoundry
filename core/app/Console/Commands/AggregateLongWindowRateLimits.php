<?php

namespace App\Console\Commands;

use App\Support\LongWindowRateLimits;
use Illuminate\Console\Command;

class AggregateLongWindowRateLimits extends Command
{
    protected $signature = 'cdnf:rate-limits:aggregate';

    protected $description = 'Add up the per-cell counts of long-window shared rate rules and mitigate keys over their threshold; prune old reports';

    public function handle(): int
    {
        $this->info('mitigations_added='.LongWindowRateLimits::aggregate());

        return self::SUCCESS;
    }
}
