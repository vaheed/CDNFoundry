<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\WafExclusion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireWafExclusions extends Command
{
    protected $signature = 'cdnf:waf:expire-exclusions {--limit=100}';

    protected $description = 'Remove due managed-WAF exclusions in bounded domain batches and reconcile signed artifacts';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $domainIds = WafExclusion::query()->where('expires_at', '<=', now())->orderBy('expires_at')
            ->limit($limit)->pluck('domain_id')->unique();
        $expired = 0;
        foreach ($domainIds as $domainId) {
            $count = DB::transaction(function () use ($domainId): int {
                $domain = Domain::query()->lockForUpdate()->find($domainId);
                if ($domain === null) {
                    return 0;
                }
                $ids = $domain->wafExclusions()->where('expires_at', '<=', now())->orderBy('id')->limit(50)->pluck('id');
                if ($ids->isEmpty()) {
                    return 0;
                }
                $deleted = WafExclusion::query()->whereIn('id', $ids)->delete();
                $domain->update(['revision' => $domain->revision + 1]);
                AuditLog::record(null, 'waf.exclusions_expired', $domain, [
                    'count' => $deleted, 'exclusion_ids' => $ids->all(), 'revision' => $domain->revision,
                ]);
                Operation::coalesceDomain('edge.domain_reconcile', $domain->id);
                ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

                return $deleted;
            });
            $expired += $count;
        }
        $this->info("Expired {$expired} managed WAF exclusion(s) across {$domainIds->count()} domain(s).");

        return self::SUCCESS;
    }
}
