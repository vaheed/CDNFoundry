<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\Operation;
use App\Support\PowerDnsClient;
use App\Support\PowerDnsZone;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ReconcileDnsZone implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId)
    {
        $this->onQueue('runtime');
    }

    public function handle(PowerDnsClient $client): void
    {
        $domain = Domain::query()->findOrFail($this->domainId);
        if ($domain->lifecycle_state !== DomainLifecycleState::Active) {
            $this->operations()->update(['status' => 'failed', 'error' => 'Only active domains can be reconciled.', 'finished_at' => now()]);

            return;
        }
        $revision = $domain->revision;
        $rrsets = PowerDnsZone::render($domain);
        $checksum = hash('sha256', json_encode($rrsets, JSON_THROW_ON_ERROR));
        $this->operations()->update(['status' => 'running', 'started_at' => now()]);
        $failures = [];

        foreach (DnsCluster::query()->where('enabled', true)->orderBy('id')->get() as $cluster) {
            $deployment = DnsDeployment::query()->firstOrCreate(
                ['domain_id' => $domain->id, 'dns_cluster_id' => $cluster->id],
                ['active_rrsets' => []],
            );
            $deployment->update([
                'desired_revision' => $revision, 'status' => 'deploying',
                'attempts' => $deployment->attempts + 1, 'last_attempted_at' => now(), 'last_error' => null,
            ]);

            if (Domain::query()->whereKey($domain->id)->value('revision') !== $revision) {
                $deployment->update(['status' => 'obsolete']);

                continue;
            }

            try {
                $client->activate($cluster, $domain->name, $rrsets, $deployment->active_rrsets ?? []);
                $deployment->update([
                    'deployed_revision' => $revision, 'status' => 'succeeded', 'active_checksum' => $checksum,
                    'active_rrsets' => $rrsets, 'last_error' => null, 'deployed_at' => now(),
                ]);
                $cluster->update(['last_reconciled_revision' => max($cluster->last_reconciled_revision, $revision)]);
            } catch (Throwable $exception) {
                $message = mb_substr($exception->getMessage(), 0, 4000);
                $deployment->update(['status' => 'failed', 'last_error' => $message]);
                $failures[] = "{$cluster->name}: $message";
            }
        }

        if ($failures !== []) {
            $message = implode('; ', $failures);
            $this->operations()->update(['status' => 'failed', 'error' => mb_substr($message, 0, 4000), 'finished_at' => now()]);
            throw new RuntimeException($message);
        }

        $latest = Domain::query()->whereKey($domain->id)->value('revision');
        $status = $latest === $revision ? 'succeeded' : 'pending';
        $this->operations()->update(['status' => $status, 'result' => ['domain_id' => $domain->id, 'revision' => $revision], 'finished_at' => $status === 'succeeded' ? now() : null]);
        if ($latest !== $revision) {
            self::dispatch($domain->id)->afterCommit();
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    private function operations()
    {
        return Operation::query()->where('type', 'dns.zone_reconcile')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $this->domainId);
    }
}
