<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\DomainNameTombstone;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\Operation;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class FinalizeDomainDeprovisioning implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function handle(): void
    {
        $domain = Domain::query()->find($this->domainId);
        if ($domain === null || $domain->lifecycle_state !== DomainLifecycleState::Deprovisioning || $domain->deprovision_after?->isFuture()) {
            return;
        }
        $clusterCount = DnsCluster::query()->count();
        if ($clusterCount > 0 && $domain->dnsDeployments()->where('status', 'deprovisioned')->count() !== $clusterCount) {
            return;
        }

        $edges = Edge::query()->where('enabled', true)->whereNotNull('registered_at')->whereNull('identity_revoked_at')->get();
        foreach ($edges as $edge) {
            $hadRuntime = EdgeArtifact::query()->where('edge_id', $edge->id)->where('domain_id', $domain->id)->where('kind', 'domain')->exists();
            if (! $hadRuntime) {
                continue;
            }
            $tombstone = EdgeArtifact::query()->where('edge_id', $edge->id)->where('domain_id', $domain->id)->where('kind', 'tombstone')->latest('sequence')->first();
            if ($tombstone === null) {
                return;
            }
            $fresh = $edge->last_heartbeat_at?->gte(now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')));
            if ($fresh && $edge->active_sequence < $tombstone->sequence) {
                return;
            }
        }

        DB::transaction(function () use ($domain): void {
            $locked = Domain::query()->lockForUpdate()->find($domain->id);
            if ($locked === null || $locked->lifecycle_state !== DomainLifecycleState::Deprovisioning) {
                return;
            }
            DomainNameTombstone::query()->updateOrCreate(['name' => $locked->name], [
                'source_domain_id' => $locked->id,
                'deprovisioned_at' => now(),
                'reclaim_after' => now()->addDays(app(PlatformSettings::class)->integer('dns_lifecycle', 'domain_reclaim_cooldown_days')),
            ]);
            $locked->users()->detach();
            AuditLog::record(null, 'domain.deprovisioned', $locked, ['reclaim_after' => now()->addDays(app(PlatformSettings::class)->integer('dns_lifecycle', 'domain_reclaim_cooldown_days'))->toIso8601String()]);
            $locked->delete();
            Operation::query()->where('type', 'domain.deprovision')->whereIn('status', ['pending', 'running'])
                ->where('input->domain_id', $locked->id)->update([
                    'status' => 'succeeded', 'result' => ['domain_id' => $locked->id, 'deprovisioned' => true],
                    'error' => null, 'finished_at' => now(),
                ]);
        });
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }
}
