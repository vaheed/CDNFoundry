<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Operation;
use App\Support\PlatformSettings;
use App\Support\PowerDnsClient;
use App\Support\PowerDnsZone;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Throwable;

class ReconcileDnsZone implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    // Retries are bounded by time, not by count: a DNS host outage of several
    // minutes must heal without a person, and the operation fails only when the
    // retry window ends. Obsolete retries are skipped by the revision checks below.
    public int $tries = 0;

    public const RETRY_WINDOW_MINUTES = 30;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId)
    {
        $this->onQueue('dns');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_WINDOW_MINUTES);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // One reconcile per domain at a time, so an older revision can never overwrite a newer one.
        return [(new WithoutOverlapping('dns-zone-'.$this->domainId))->releaseAfter(10)->expireAfter(300)];
    }

    public function failed(Throwable $exception): void
    {
        $this->operations()->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
    }

    public function handle(PowerDnsClient $client): void
    {
        $domain = Domain::query()->find($this->domainId);
        if ($domain === null) {
            // The domain was deleted; retrying cannot help.
            $this->operations()->update(['status' => 'failed', 'error' => 'The domain no longer exists.', 'finished_at' => now()]);

            return;
        }
        if (! in_array($domain->lifecycle_state, [DomainLifecycleState::PendingVerification, DomainLifecycleState::Active], true)) {
            $this->operations()->update(['status' => 'failed', 'error' => 'Only pending-verification or active domains can be reconciled.', 'finished_at' => now()]);

            return;
        }
        if ($domain->lifecycle_state === DomainLifecycleState::PendingVerification && ($domain->hasManagedAncestor() || $domain->isSecondaryClaim())) {
            // An unverified child must not shadow its parent's authoritative records, and a competing
            // claim must not overwrite the zone of the name's holder. Parent delegation is checked
            // without publishing this zone.
            $this->operations()->update(['status' => 'succeeded', 'result' => ['domain_id' => $domain->id, 'bootstrap_deferred' => true], 'finished_at' => now()]);
            VerifyDomainNameservers::dispatch($domain->id)->afterCommit();

            return;
        }
        $revision = $domain->revision;
        try {
            $rrsets = $domain->lifecycle_state === DomainLifecycleState::PendingVerification
                ? PowerDnsZone::renderDelegation($domain)
                : PowerDnsZone::render($domain);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 4000);
            // A zone that cannot be rendered is a permanent failure; retrying cannot help.
            $this->operations()->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            $this->fail(new RuntimeException($message, previous: $exception));

            return;
        }
        $checksum = hash('sha256', json_encode($rrsets, JSON_THROW_ON_ERROR));
        $this->operations()->update(['status' => 'running', 'started_at' => now()]);
        $failures = [];
        $targets = 0;

        foreach (DnsCluster::query()->where('enabled', true)->orderBy('id')->get() as $cluster) {
            $targets++;
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

        if ($targets === 0) {
            $failures[] = 'No enabled DNS cluster is available.';
        }

        if ($failures !== []) {
            $message = implode('; ', $failures);
            // Keep the operation running and visible while retries remain; failed() ends it.
            $this->operations()->update(['status' => 'running', 'error' => mb_substr('Retrying: '.$message, 0, 4000)]);
            throw new RuntimeException($message);
        }

        $latest = Domain::query()->whereKey($domain->id)->value('revision');
        $status = $latest === $revision ? 'succeeded' : 'pending';
        if ($status === 'succeeded') {
            DomainEdgePlacement::query()->where('domain_id', $domain->id)->where('state', 'draining')
                ->whereNotNull('target_pool_id')->whereNull('drain_after')
                ->update(['drain_after' => now()->addSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'placement_drain_seconds'))]);
        }
        $this->operations()->update(['status' => $status, 'error' => null, 'result' => ['domain_id' => $domain->id, 'revision' => $revision], 'finished_at' => $status === 'succeeded' ? now() : null]);
        if ($latest !== $revision) {
            self::dispatch($domain->id)->afterCommit();
        } elseif ($domain->lifecycle_state === DomainLifecycleState::PendingVerification && $domain->nameservers_verified_at === null
            && Operation::query()->where('type', 'domain.nameservers_verify')->whereIn('status', ['pending', 'running'])
                ->where('input->domain_id', $domain->id)->exists()) {
            VerifyDomainNameservers::dispatch($domain->id)->afterCommit();
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
