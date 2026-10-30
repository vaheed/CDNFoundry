<?php

namespace App\Console\Commands;

use App\Enums\DomainLifecycleState;
use App\Jobs\ReconcileDnsZone;
use App\Models\AuditLog;
use App\Models\DnsDeployment;
use App\Models\Operation;
use App\Support\PowerDnsClient;
use App\Support\PowerDnsZone;
use Illuminate\Console\Command;
use Throwable;

class RepairDnsDrift extends Command
{
    protected $signature = 'cdnf:dns:repair-drift {--limit=100 : Maximum deployments to check}';

    protected $description = 'Compare each DNS target\'s served zone with desired state, oldest check first, and queue a reconcile for every difference';

    public function handle(PowerDnsClient $client): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $repaired = 0;
        $checked = 0;
        $deployments = DnsDeployment::query()->with(['domain', 'cluster'])->where('tombstone', false)
            ->whereHas('cluster', fn ($query) => $query->where('enabled', true))
            ->whereHas('domain', fn ($query) => $query->whereIn('lifecycle_state', [DomainLifecycleState::PendingVerification, DomainLifecycleState::Active]))
            ->orderByRaw('verified_at IS NULL DESC')->orderBy('verified_at')->orderBy('id')->limit($limit)->get();

        foreach ($deployments as $deployment) {
            $domain = $deployment->domain;
            $checked++;
            $reason = null;
            try {
                $rrsets = $domain->lifecycle_state === DomainLifecycleState::PendingVerification
                    ? PowerDnsZone::renderDelegation($domain) : PowerDnsZone::render($domain);
                if ($deployment->status !== 'succeeded' || $deployment->active_checksum !== hash('sha256', json_encode($rrsets, JSON_THROW_ON_ERROR))) {
                    $reason = $deployment->status !== 'succeeded' ? "deployment_{$deployment->status}" : 'desired_state_changed';
                } elseif (! $client->matches($deployment->cluster, $domain->name, $deployment->active_rrsets ?? [])) {
                    $reason = 'served_zone_differs';
                }
            } catch (Throwable) {
                // An unreachable target or an unrenderable zone is not drift. A reachable
                // target is checked again on the next pass and a failed deployment is retried above.
                $deployment->update(['verified_at' => now()]);

                continue;
            }
            $deployment->update(['verified_at' => now()]);
            if ($reason === null) {
                continue;
            }
            $repaired++;
            Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
            ReconcileDnsZone::dispatch($domain->id);
            AuditLog::record(null, 'dns.drift_repair_queued', $domain, ['dns_cluster_id' => $deployment->dns_cluster_id, 'reason' => $reason]);
        }
        $this->info("Checked {$checked} DNS deployment(s); queued {$repaired} repair(s).");

        return self::SUCCESS;
    }
}
