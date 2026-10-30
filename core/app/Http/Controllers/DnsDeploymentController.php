<?php

namespace App\Http\Controllers;

use App\Enums\DomainLifecycleState;
use App\Http\Resources\DnsDeploymentResource;
use App\Http\Resources\OperationResource;
use App\Jobs\ReconcileDnsZone;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DnsDeploymentController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);
        $deployments = $domain->dnsDeployments()->with(['domain:id,name', 'cluster:id,name,location,enabled'])->orderBy('dns_cluster_id')->get();

        return response()->json(['data' => ['domain_id' => $domain->id, 'desired_revision' => $domain->revision, 'deployments' => DnsDeploymentResource::collection($deployments)->resolve()]]);
    }

    public function reconcile(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_unless($domain->lifecycle_state === DomainLifecycleState::Active, 409, 'Only active domains can be reconciled.');
        $operation = Operation::query()->where('type', 'dns.zone_reconcile')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $domain->id)->first();
        if ($operation === null) {
            $operation = Operation::query()->create([
                'actor_id' => $request->user()->getKey(), 'type' => 'dns.zone_reconcile',
                'status' => 'pending', 'input' => ['domain_id' => $domain->id, 'revision' => $domain->revision],
            ]);
            ReconcileDnsZone::dispatch($domain->id)->afterCommit();
            AuditLog::record($request->user(), 'dns.reconcile_requested', $domain, ['operation_id' => $operation->id, 'revision' => $domain->revision], $request->ip());
        }

        return response()->json(['data' => OperationResource::make($operation)->resolve()], 202);
    }
}
