<?php

namespace App\Console\Commands;

use App\Jobs\BuildUsageRollups;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class FinalizeUsage extends Command
{
    protected $signature = 'usage:finalize';

    protected $description = 'Dispatch an idempotent rebuild for the most recently finalized usage hour';

    public function handle(): int
    {
        $to = CarbonImmutable::now('UTC')->subHour()->startOfHour();
        BuildUsageRollups::dispatch($to->subHour()->toIso8601String(), $to->toIso8601String());
        $this->info("Queued usage interval ending {$to->toIso8601String()}.");

        return self::SUCCESS;
    }
}
