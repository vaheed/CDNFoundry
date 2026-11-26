<?php

namespace App\Console\Commands;

use App\Support\ClientReputation;
use Illuminate\Console\Command;

class AggregateClientReputation extends Command
{
    protected $signature = 'cdnf:client-reputation:aggregate';

    protected $description = 'Add up what cells reported about clients of enrolled domains, update the shadow reputation bands and prune old reports';

    public function handle(): int
    {
        $this->info('clients_banded='.ClientReputation::aggregate());

        return self::SUCCESS;
    }
}
