<?php

namespace App\Support;

use App\Enums\DomainLifecycleState;
use App\Jobs\EnsureManagedCertificates;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\VerifyDomainNameservers;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\DnsDeployment;
use App\Models\Domain;
use App\Models\EdgeArtifact;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class DomainNameserverVerification
{
    public function queue(Domain $domain, User $actor, ?string $ipAddress = null, bool $automatic = false): Operation
    {
        return DB::transaction(function () use ($domain, $actor, $ipAddress, $automatic): Operation {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            Gate::forUser($actor)->authorize('update', $domain);
            abort_if($domain->claim_expires_at?->isPast() && $domain->nameservers_verified_at === null, 409, 'This pending claim has expired; contact an administrator for recovery.');
            $domain->initializeDelegationClaim();
            abort_unless(in_array($domain->lifecycle_state, [DomainLifecycleState::PendingVerification, DomainLifecycleState::Active], true), 409, 'Only pending or active domains can be verified.');
            $operation = Operation::query()->where('type', 'domain.nameservers_verify')
                ->whereIn('status', ['pending', 'running'])
                ->where('input->domain_id', $domain->id)
                ->lockForUpdate()
                ->first();
            if ($operation !== null) {
                $previousActor = $operation->actor()->first();
                if ($previousActor === null || Gate::forUser($previousActor)->denies('update', $domain)
                    || ($domain->nameservers_verified_at === null && ($operation->input['delegation_token'] ?? null) !== $domain->delegation_token)) {
                    $operation->update(['status' => 'cancelled', 'finished_at' => now(), 'error' => 'The applicant or delegation assignment changed.']);
                    $operation = null;
                }
            }
            $verificationCreated = $operation === null;

            if ($operation === null) {
                $operation = Operation::query()->create([
                    'actor_id' => $actor->getKey(),
                    'type' => 'domain.nameservers_verify',
                    'status' => 'pending',
                    'input' => ['domain_id' => $domain->id, 'delegation_token' => $domain->delegation_token],
                ]);
                AuditLog::record($actor, 'domain.nameserver_verification_requested', $domain, [
                    'automatic' => $automatic,
                ], $ipAddress);
            }

            if ($domain->hasManagedAncestor() || $this->zoneIsReady($domain)) {
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

    /**
     * Atomically record successful verification and move the desired domain state to active.
     */
    public function complete(
        Domain $domain,
        ?User $actor = null,
        ?Operation $verification = null,
        array $observedNameservers = [],
        ?string $ipAddress = null,
        bool $forced = false,
    ): bool {
        return DB::transaction(function () use ($domain, $actor, $verification, $observedNameservers, $ipAddress, $forced): bool {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if (! in_array($locked->lifecycle_state, [DomainLifecycleState::PendingVerification, DomainLifecycleState::Active], true)) {
                throw new RuntimeException('Only pending or active domains can be verified.');
            }
            $actor = $actor === null ? null : User::query()->lockForUpdate()->find($actor->getKey());
            if ($actor === null || $actor->isDisabled() || ($forced && ! $actor->isAdmin())) {
                throw new RuntimeException('Verification requires a currently authorized actor.');
            }
            if (! $actor->isAdmin() && $locked->users()->whereKey($actor->id)->lockForUpdate()->first() === null) {
                throw new RuntimeException('The applicant assignment has been revoked.');
            }
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->nameservers_verified_at === null && $locked->claim_expires_at?->isPast()) {
                throw new RuntimeException('This pending delegation claim has expired.');
            }
            if (! $forced) {
                $verification = $verification === null ? null : Operation::query()->lockForUpdate()->find($verification->id);
                if ($verification === null || ! in_array($verification->status, ['pending', 'running'], true)
                    || $verification->actor_id !== $actor->id || (int) ($verification->input['domain_id'] ?? 0) !== $locked->id) {
                    throw new RuntimeException('The verification operation is no longer authorized.');
                }
                if ($locked->nameservers_verified_at === null && ($locked->delegation_token === null
                    || ($verification->input['delegation_token'] ?? null) !== $locked->delegation_token
                    || $observedNameservers !== $locked->assignedNameservers())) {
                    throw new RuntimeException('Delegation does not match the current applicant assignment.');
                }
            }
            if (! DnsCluster::query()->where('enabled', true)->where('last_health_status', 'healthy')->exists()) {
                throw new RuntimeException('Enable at least one healthy DNS cluster before verification can activate the domain.');
            }

            $wasVerified = $locked->nameservers_verified_at !== null;
            $activated = $locked->lifecycle_state !== DomainLifecycleState::Active;
            $changes = [
                'nameservers_verified_at' => $locked->nameservers_verified_at ?? now(),
                'nameservers_verified_by' => $wasVerified ? $locked->nameservers_verified_by : ($forced ? $actor?->getKey() : null),
            ];
            if ($activated) {
                $changes += [
                    'lifecycle_state' => DomainLifecycleState::Active,
                    'disabled_at' => null,
                    'revision' => $locked->revision + 1,
                ];
            }
            $locked->forceFill($changes)->save();

            if ($forced && ! $wasVerified) {
                AuditLog::record($actor, 'domain.nameservers_force_verified', $locked, ['name' => $locked->name], $ipAddress);
            }
            if ($activated) {
                AuditLog::record($actor, 'domain.activated', $locked, [
                    'revision' => $locked->revision,
                    'automatic' => true,
                    'verification' => $forced ? 'forced' : 'public_delegation',
                ], $ipAddress);

                $reconciliation = Operation::query()->where('type', 'dns.zone_reconcile')
                    ->whereIn('status', ['pending', 'running'])
                    ->where('input->domain_id', $locked->id)
                    ->lockForUpdate()
                    ->first();
                if ($reconciliation === null) {
                    Operation::query()->create([
                        'actor_id' => $actor?->getKey(),
                        'type' => 'dns.zone_reconcile',
                        'status' => 'pending',
                        'input' => ['domain_id' => $locked->id, 'revision' => $locked->revision],
                    ]);
                } else {
                    $reconciliation->update(['input' => ['domain_id' => $locked->id, 'revision' => $locked->revision]]);
                }
            }

            if ($verification !== null) {
                Operation::query()->whereKey($verification->id)->lockForUpdate()->first()?->update([
                    'status' => 'succeeded',
                    'result' => ['domain_id' => $locked->id, 'nameservers' => $observedNameservers, 'activated' => $locked->lifecycle_state === DomainLifecycleState::Active],
                    'finished_at' => now(),
                    'error' => null,
                ]);
            }

            return $activated;
        });
    }

    public function dispatchActivation(int $domainId, ?int $actorId = null): void
    {
        ReconcileDnsZone::dispatch($domainId)->afterCommit();
        $domain = Domain::query()->findOrFail($domainId);
        if ($domain->dnsRecords()->where('mode', 'proxied')->exists() || EdgeArtifact::query()->where('domain_id', $domainId)->exists()) {
            Operation::coalesceDomain('edge.domain_reconcile', $domainId, $actorId);
            ReconcileEdgeDomain::dispatch($domainId)->afterCommit();
            EnsureManagedCertificates::dispatch($domainId)->afterCommit();
        }
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
