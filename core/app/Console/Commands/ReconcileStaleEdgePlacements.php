<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\DomainEdgePlacement;
use App\Models\Operation;
use Illuminate\Console\Command;

class ReconcileStaleEdgePlacements extends Command
{
    protected $signature = 'cdnf:edge:reconcile-stale-placements {--limit=100 : Maximum placements to queue}';

    protected $description = 'Requeue bounded stale edge placements so interrupted deployments converge';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $placements = DomainEdgePlacement::query()
            ->where('state', 'deploying')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->whereHas('domain.dnsRecords', fn ($query) => $query->where('mode', 'proxied'))
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->get();

        foreach ($placements as $placement) {
            Operation::coalesceDomain('edge.domain_reconcile', $placement->domain_id);
            ReconcileEdgeDomain::dispatch($placement->domain_id);
        }

        $this->info("Queued {$placements->count()} stale edge placement(s).");

        return self::SUCCESS;
    }
}
