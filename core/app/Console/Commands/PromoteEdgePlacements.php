<?php

namespace App\Console\Commands;

use App\Actions\PromoteReadyEdgePlacements;
use Illuminate\Console\Command;

class PromoteEdgePlacements extends Command
{
    protected $signature = 'cdnf:edge:promote-placements {--chunk=100} {--chunks=50}';

    protected $description = 'Promote deploying placements whose healthy PoPs have acknowledged, paging through all of them';

    public function handle(): int
    {
        PromoteReadyEdgePlacements::execute((int) $this->option('chunk'), (int) $this->option('chunks'));

        return self::SUCCESS;
    }
}
