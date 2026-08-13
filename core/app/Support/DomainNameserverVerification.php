<?php

namespace App\Support;

use App\Jobs\ReconcileDnsZone;
use App\Jobs\VerifyDomainNameservers;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DomainNameserverVerification
{
    public function queue(Domain $domain, User $actor, ?string $ipAddress = null, bool $automatic = false): Operation
    {
        return DB::transaction(function () use ($domain, $actor, $ipAddress, $automatic): Operation {
            $operation = Operation::query()->where('type', 'domain.nameservers_verify')
                ->whereIn('status', ['pending', 'running'])
                ->where('input->domain_id', $domain->id)
                ->first();
            $verificationCreated = $operation === null;

            if ($operation === null) {
                $operation = Operation::query()->create([
                    'actor_id' => $actor->getKey(),
                    'type' => 'domain.nameservers_verify',
                    'status' => 'pending',
                    'input' => ['domain_id' => $domain->id],
                ]);
                AuditLog::record($actor, 'domain.nameserver_verification_requested', $domain, [
                    'automatic' => $automatic,
                ], $ipAddress);
            }

            if ($this->zoneIsReady($domain)) {
                if ($verificationCreated) {
                    VerifyDomainNameservers::dispatch($domain->id)->afterCommit();
                }

                return $operation;
            }

            $reconciliation = Operation::query()->where('type', 'dns.zone_reconcile')
                ->whereIn('status', ['pending', 'running'])
                ->where('input->domain_id', $domain->id)
                ->first();

            if ($reconciliation === null) {
                Operation::query()->create([
                    'actor_id' => $actor->getKey(),
                    'type' => 'dns.zone_reconcile',
                    'status' => 'pending',
                    'input' => ['domain_id' => $domain->id, 'revision' => $domain->revision],
                ]);
                ReconcileDnsZone::dispatch($domain->id)->afterCommit();
            }

            return $operation;
        });
    }

    private function zoneIsReady(Domain $domain): bool
    {
        $enabledClusterIds = DnsCluster::query()->where('enabled', true)->pluck('id');
        if ($enabledClusterIds->isEmpty()) {
            return false;
        }

        $readyTargets = DnsDeployment::query()
            ->where('domain_id', $domain->id)
            ->whereIn('dns_cluster_id', $enabledClusterIds)
            ->where('status', 'succeeded')
            ->where('deployed_revision', $domain->revision)
            ->distinct()
            ->count('dns_cluster_id');

        return $readyTargets === $enabledClusterIds->count();
    }
}
