<?php

namespace App\Console\Commands;

use App\Models\AcmeChallenge;
use App\Models\CachePurge;
use App\Models\DomainNameTombstone;
use App\Models\EdgeTask;
use App\Models\TlsOrder;
use App\Support\PlatformSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes one bounded batch of finished delivery history that nothing reads again: edge tasks, cache purges with their
 * URL lists, certificate orders, and expired domain-name holds. Desired state, certificates, and usage are never touched.
 */
class PruneFinishedHistory extends Command
{
    protected $signature = 'cdnf:history:prune {--batch=5000}';

    protected $description = 'Delete finished edge tasks, cache purges, certificate orders, and expired name holds beyond their retention window';

    public function handle(PlatformSettings $settings): int
    {
        $batch = max(1, min(10000, (int) $this->option('batch')));
        $days = fn (string $key): Carbon => now()->subDays($settings->integer('operations', $key));

        $purges = CachePurge::query()->whereIn('status', ['succeeded', 'failed'])->where('updated_at', '<', $days('cache_purge_retention_days'))->orderBy('updated_at')->limit($batch)->pluck('id');
        $purgesDeleted = $purges->isEmpty() ? 0 : CachePurge::query()->whereIn('id', $purges)->delete();

        $tasks = EdgeTask::query()->whereIn('status', ['succeeded', 'failed'])->where('updated_at', '<', $days('edge_task_retention_days'))->orderBy('updated_at')->limit($batch)->pluck('id');
        $tasksDeleted = $tasks->isEmpty() ? 0 : EdgeTask::query()->whereIn('id', $tasks)->delete();

        // The newest order of every domain stays, because the domain page reports its last certificate problem from it.
        $orders = TlsOrder::query()->whereIn('status', ['succeeded', 'failed', 'obsolete'])->where('updated_at', '<', $days('tls_order_retention_days'))
            ->whereExists(fn ($newer) => $newer->from('tls_orders as newer')->whereColumn('newer.domain_id', 'tls_orders.domain_id')->whereColumn('newer.created_at', '>', 'tls_orders.created_at')->selectRaw('1'))
            ->orderBy('updated_at')->limit($batch)->pluck('id');
        if ($orders->isNotEmpty()) {
            AcmeChallenge::query()->whereIn('tls_order_id', $orders)->delete();
        }
        $ordersDeleted = $orders->isEmpty() ? 0 : TlsOrder::query()->whereIn('id', $orders)->delete();

        $holds = DomainNameTombstone::query()->where('reclaim_after', '<', now()->subDays(30))->orderBy('reclaim_after')->limit($batch)->pluck('id');
        $holdsDeleted = $holds->isEmpty() ? 0 : DomainNameTombstone::query()->whereIn('id', $holds)->delete();

        $this->info("Deleted {$purgesDeleted} cache purges, {$tasksDeleted} edge tasks, {$ordersDeleted} certificate orders, and {$holdsDeleted} name holds.");

        return self::SUCCESS;
    }
}
