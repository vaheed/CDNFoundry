<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\DomainEdgePlacement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CompleteEdgePlacementDrains extends Command
{
    protected $signature = 'edge:complete-placement-drains {--limit=100}';

    protected $description = 'Promote ready target pools after their bounded DNS drain period';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $ids = DomainEdgePlacement::query()->where('state', 'draining')->whereNotNull('target_pool_id')
            ->where('drain_after', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $domainId = DB::transaction(function () use ($id): ?int {
                $placement = DomainEdgePlacement::query()->lockForUpdate()->find($id);
                if ($placement === null || $placement->state !== 'draining' || $placement->drain_after?->isFuture() || $placement->target_pool_id === null) {
                    return null;
                }
                $source = $placement->active_pool_id;
                $placement->update(['active_pool_id' => $placement->target_pool_id, 'target_pool_id' => null, 'state' => 'active', 'drain_after' => null]);
                AuditLog::record(null, 'edge.placement_drain_completed', $placement, ['source_pool_id' => $source, 'active_pool_id' => $placement->active_pool_id]);

                return $placement->domain_id;
            });
            if ($domainId !== null) {
                ReconcileEdgeDomain::dispatch($domainId);
            }
        }
        $this->info(sprintf('Completed %d edge placement drain(s).', $ids->count()));

        return self::SUCCESS;
    }
}
