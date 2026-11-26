<?php

namespace App\Console\Commands;

use App\Support\ChallengeKeys;
use Illuminate\Console\Command;

class MaintainChallengeKeys extends Command
{
    protected $signature = 'cdnf:challenge-keys:maintain';

    protected $description = 'Rotate the active challenge key when it is old, retire keys whose overlap is over, and prune old retired keys';

    public function handle(): int
    {
        $changed = ChallengeKeys::maintain();
        $this->info(sprintf('rotated=%s retired=%d pruned=%d', $changed['rotated'] ? 'yes' : 'no', $changed['retired'], $changed['pruned']));

        return self::SUCCESS;
    }
}
