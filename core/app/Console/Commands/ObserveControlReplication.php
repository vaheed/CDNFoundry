<?php

namespace App\Console\Commands;

use App\Models\ControlNode;
use App\Support\ControlPlane;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ObserveControlReplication extends Command
{
    protected $signature = 'cdnf:control:observe';

    protected $description = 'Record the standby\'s replication lag and Valkey link from the active node';

    public function handle(): int
    {
        $standby = ControlNode::query()->where('role', 'standby')->first();
        if ($standby === null || DB::getDriverName() !== 'pgsql' || ControlPlane::fencedReason() !== null) {
            return self::SUCCESS;
        }
        $streaming = false;
        $lag = null;
        try {
            $row = DB::selectOne("select count(*) as streaming, coalesce(max(extract(epoch from replay_lag)), 0)::int as lag from pg_stat_replication where state = 'streaming'");
            $streaming = (int) $row->streaming > 0;
            $lag = $streaming ? (int) $row->lag : null;
        } catch (Throwable) {
        }
        $linked = false;
        try {
            $info = Redis::connection()->info('replication');
            $info = $info['Replication'] ?? $info;
            $linked = (int) ($info['connected_slaves'] ?? 0) > 0;
        } catch (Throwable) {
        }
        // `reported_at` moves only while the standby is streaming, so a silent standby turns stale.
        $standby->update(['replication_lag_seconds' => $lag, 'valkey_linked' => $linked, ...($streaming ? ['reported_at' => now()] : [])]);

        return self::SUCCESS;
    }
}
