<?php

namespace App\Console\Commands;

use App\Support\ControlPlane;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ControlGuard extends Command
{
    protected $signature = 'cdnf:control:guard';

    protected $description = 'Exit non-zero unless this node may run application services (writable primary, active node, highest epoch)';

    public function handle(): int
    {
        try {
            $reason = ControlPlane::fencedReason(consultPeers: true);
        } catch (\Throwable $exception) {
            // Before the migration that creates the state there is nothing to fence; an unreadable
            // database any other way keeps the node stopped.
            try {
                $reason = DB::selectOne('select pg_is_in_recovery() as recovery')->recovery ?? false ? 'database_in_recovery' : (Schema::hasTable('control_plane_state') ? 'state_unreadable' : null);
            } catch (\Throwable) {
                $reason = 'state_unreadable';
            }
        }
        if ($reason !== null) {
            $this->error("This control node is fenced: {$reason}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
