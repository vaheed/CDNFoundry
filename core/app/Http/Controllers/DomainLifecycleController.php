<?php

namespace App\Http\Controllers;

use App\Enums\DomainLifecycleState;
use App\Http\Resources\DomainResource;
use App\Jobs\EnsureManagedCertificates;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\VerifyDomainNameservers;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\EdgeArtifact;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DomainLifecycleController extends Controller
{
    public function status(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => [
            'domain' => DomainResource::make($domain),
            'nameservers_verified' => $domain->nameservers_verified_at !== null,
            'deployment' => [
                'desired_revision' => $domain->revision,
                'targets' => $domain->dnsDeployments()->select(['dns_cluster_id', 'desired_revision', 'deployed_revision', 'status', 'last_error', 'deployed_at', 'tombstone', 'deprovisioned_at'])->orderBy('dns_cluster_id')->get(),
            ],
        ]]);
    }

    public function verify(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_if($domain->lifecycle_state === DomainLifecycleState::Deprovisioning, 409, 'A deprovisioning domain cannot be verified.');
        $operation = Operation::query()->where('type', 'domain.nameservers_verify')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $domain->id)->first();
        if ($operation === null) {
            $operation = Operation::query()->create([
                'actor_id' => $request->user()->getKey(), 'type' => 'domain.nameservers_verify',
                'status' => 'pending', 'input' => ['domain_id' => $domain->id],
            ]);
            AuditLog::record($request->user(), 'domain.nameserver_verification_requested', $domain, [], $request->ip());
            VerifyDomainNameservers::dispatch($domain->id)->afterCommit();
        }

        return response()->json(['data' => $operation], 202);
    }

    public function activate(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_if($domain->lifecycle_state === DomainLifecycleState::Deprovisioning, 409, 'A deprovisioning domain cannot be activated.');
        abort_if($domain->nameservers_verified_at === null, 409, 'Verify the required nameservers before activation.');
        abort_unless(
            DnsCluster::query()->where('enabled', true)->where('last_health_status', 'healthy')->exists(),
            409,
            'Enable at least one healthy DNS cluster before activation.',
        );

        $operation = DB::transaction(function () use ($request, $domain): Operation {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($locked->lifecycle_state !== DomainLifecycleState::Active) {
                $locked->forceFill(['lifecycle_state' => DomainLifecycleState::Active, 'disabled_at' => null, 'revision' => $locked->revision + 1])->save();
                AuditLog::record($request->user(), 'domain.activated', $locked, ['revision' => $locked->revision], $request->ip());
            }

            return Operation::query()->where('type', 'dns.zone_reconcile')->whereIn('status', ['pending', 'running'])
                ->where('input->domain_id', $locked->id)->first() ?? Operation::query()->create([
                    'actor_id' => $request->user()->getKey(), 'type' => 'dns.zone_reconcile', 'status' => 'pending',
                    'input' => ['domain_id' => $locked->id, 'revision' => $locked->revision],
                ]);
        });
        ReconcileDnsZone::dispatch($domain->id)->afterCommit();
        if ($domain->dnsRecords()->where('mode', 'proxied')->exists() || EdgeArtifact::query()->where('domain_id', $domain->id)->exists()) {
            Operation::coalesceDomain('edge.domain_reconcile', $domain->id, $request->user()->id);
            ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
            EnsureManagedCertificates::dispatch($domain->id)->afterCommit();
        }

        return response()->json(['data' => $operation->refresh()], 202);
    }
}
