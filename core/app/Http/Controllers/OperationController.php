<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyPlatformDnsSettings;
use App\Jobs\DispatchOriginTest;
use App\Jobs\ImportDnsZone;
use App\Jobs\ProvisionEdgePoolCells;
use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\TestDnsCluster;
use App\Jobs\VerifyDomainNameservers;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationController extends Controller
{
    public function show(Request $request, Operation $operation): JsonResource
    {
        abort_unless($request->user()->isAdmin() || $operation->actor_id === $request->user()->getKey(), 403);

        return JsonResource::make($operation);
    }

    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(Operation::query()->latest()->cursorPaginate(50));
    }

    public function retry(Request $request, Operation $operation): JsonResponse
    {
        abort_unless($operation->status === 'failed', 409, 'Only failed operations can be retried.');
        abort_unless(in_array($operation->type, ['platform_dns_identity.update', 'system_settings.update', 'domain.nameservers_verify', 'dns.zone_reconcile', 'dns.zone_import', 'dns.cluster_test', 'dns.global_reconcile', 'edge.global_reconcile', 'edge.pool_provision', 'edge.domain_reconcile', 'edge.origin_test'], true), 422, 'Unsupported operation type.');
        $operation->update(['status' => 'pending', 'error' => null, 'finished_at' => null]);
        AuditLog::record($request->user(), 'operation.retry_requested', $operation, [], $request->ip());
        match ($operation->type) {
            'platform_dns_identity.update' => ApplyPlatformDnsSettings::dispatch($operation->getKey()),
            'system_settings.update' => PlatformSettings::dispatchOperation($operation),
            'domain.nameservers_verify' => VerifyDomainNameservers::dispatch((int) $operation->input['domain_id']),
            'dns.zone_reconcile' => ReconcileDnsZone::dispatch((int) $operation->input['domain_id']),
            'dns.zone_import' => ImportDnsZone::dispatch($operation->getKey()),
            'dns.cluster_test' => TestDnsCluster::dispatch($operation->getKey()),
            'dns.global_reconcile' => ReconcileAllDnsZones::dispatch($operation->getKey()),
            'edge.global_reconcile' => ReconcileAllEdgeDomains::dispatch($operation->getKey()),
            'edge.pool_provision' => ProvisionEdgePoolCells::dispatch((int) $operation->input['pool_id'], $operation->id),
            'edge.domain_reconcile' => ReconcileEdgeDomain::dispatch((int) $operation->input['domain_id']),
            'edge.origin_test' => DispatchOriginTest::dispatch($operation->getKey()),
        };

        return response()->json(['data' => $operation->refresh()], 202);
    }
}
