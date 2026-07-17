<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DnsDeploymentResource;
use App\Jobs\ReconcileAllDnsZones;
use App\Models\AuditLog;
use App\Models\DnsDeployment;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DnsOperationController extends Controller
{
    public function deployments(): AnonymousResourceCollection
    {
        return DnsDeploymentResource::collection(DnsDeployment::query()->with(['domain:id,name', 'cluster:id,name,location'])->orderBy('id')->cursorPaginate(50));
    }

    public function failures(): AnonymousResourceCollection
    {
        return DnsDeploymentResource::collection(DnsDeployment::query()->with(['domain:id,name', 'cluster:id,name,location'])
            ->where('status', 'failed')->orderBy('id')->cursorPaginate(50));
    }

    public function reconcile(Request $request): JsonResponse
    {
        $operation = Operation::query()->where('type', 'dns.global_reconcile')->whereIn('status', ['pending', 'running'])->first();
        if ($operation === null) {
            $operation = Operation::query()->create(['actor_id' => $request->user()->getKey(), 'type' => 'dns.global_reconcile', 'status' => 'pending', 'input' => []]);
            AuditLog::record($request->user(), 'dns.global_reconcile_requested', $operation, [], $request->ip());
            ReconcileAllDnsZones::dispatch($operation->getKey())->afterCommit();
        }

        return response()->json(['data' => $operation], 202);
    }
}
