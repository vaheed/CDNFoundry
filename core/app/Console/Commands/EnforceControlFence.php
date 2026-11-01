<?php

namespace App\Console\Commands;

use App\Support\ControlPlane;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class EnforceControlFence extends Command
{
    protected $signature = 'cdnf:control:enforce';

    protected $description = 'Stop Horizon on a node that was replaced; its restart then fails the start guard';

    public function handle(): int
    {
        ControlPlane::forgetFence();
        $reason = ControlPlane::fencedReason(consultPeers: true);
        if ($reason !== null) {
            $this->warn("Fenced ({$reason}); asking Horizon to terminate.");
            Artisan::call('horizon:terminate');
        }

        return self::SUCCESS;
    }
}
