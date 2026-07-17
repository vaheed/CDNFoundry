<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Support\PowerDnsClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class DeprovisionDnsZone implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(public int $domainId)
    {
        $this->onQueue('runtime');
    }

    public function handle(PowerDnsClient $client): void
    {
        $domain = Domain::query()->findOrFail($this->domainId);
        if ($domain->lifecycle_state !== DomainLifecycleState::Deprovisioning || $domain->deprovision_after?->isFuture()) {
            return;
        }
        $failures = [];
        foreach (DnsCluster::query()->orderBy('id')->get() as $cluster) {
            $deployment = DnsDeployment::query()->firstOrCreate(
                ['domain_id' => $domain->id, 'dns_cluster_id' => $cluster->id],
                ['desired_revision' => $domain->revision, 'deployed_revision' => 0, 'active_rrsets' => []],
            );
            $deployment->update(['tombstone' => true, 'status' => 'deprovisioning', 'desired_revision' => $domain->revision, 'attempts' => $deployment->attempts + 1, 'last_attempted_at' => now()]);
            try {
                $client->deleteZone($cluster, $domain->name);
                $deployment->update(['status' => 'deprovisioned', 'active_rrsets' => [], 'active_checksum' => null, 'last_error' => null, 'deprovisioned_at' => now()]);
            } catch (Throwable $exception) {
                $message = mb_substr($exception->getMessage(), 0, 4000);
                $deployment->update(['status' => 'failed', 'last_error' => $message]);
                $failures[] = "{$cluster->name}: $message";
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(implode('; ', $failures));
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }
}
