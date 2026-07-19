<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\EdgeArtifact;
use App\Models\EdgeRevision;
use App\Support\PlatformSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneEdgeRevisionHistory extends Command
{
    protected $signature = 'edge:prune-revisions {--limit=1000}';

    protected $description = 'Prune expired derived edge revisions while preserving current state and recent rollback points';

    public function handle(PlatformSettings $settings): int
    {
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $cutoff = now()->subDays($settings->integer('revision_history', 'retention_days'));
        $minimum = $settings->integer('revision_history', 'minimum_revisions_per_domain');
        $domainIds = EdgeRevision::query()->where('created_at', '<', $cutoff)->orderBy('domain_id')
            ->limit($limit)->pluck('domain_id')->unique();
        $revisionCount = 0;
        $artifactCount = 0;

        foreach ($domainIds as $domainId) {
            if ($revisionCount >= $limit) {
                break;
            }
            $domain = Domain::withTrashed()->find($domainId);
            $protected = EdgeRevision::query()->where('domain_id', $domainId)->latest('revision')
                ->limit($minimum)->pluck('revision');
            $protected->push($domain?->revision, $domain?->active_edge_revision);
            $protected->push(DomainEdgePlacement::query()->where('domain_id', $domainId)->value('desired_revision'));
            $protected = $protected->filter(fn (mixed $revision): bool => $revision !== null)->map(fn (mixed $revision): int => (int) $revision)->unique();
            $revisions = EdgeRevision::query()->where('domain_id', $domainId)->where('created_at', '<', $cutoff)
                ->whereNotIn('revision', $protected)->oldest('revision')->limit($limit - $revisionCount)->pluck('revision');
            if ($revisions->isEmpty()) {
                continue;
            }
            [$deletedRevisions, $deletedArtifacts] = DB::transaction(function () use ($domainId, $revisions): array {
                $artifacts = EdgeArtifact::query()->where('domain_id', $domainId)->whereIn('revision', $revisions)->delete();
                $history = EdgeRevision::query()->where('domain_id', $domainId)->whereIn('revision', $revisions)->delete();

                return [$history, $artifacts];
            });
            $revisionCount += $deletedRevisions;
            $artifactCount += $deletedArtifacts;
        }

        $this->info("Pruned {$revisionCount} edge revision(s) and {$artifactCount} derived artifact(s).");

        return self::SUCCESS;
    }
}
