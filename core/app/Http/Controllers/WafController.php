<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\WafExclusion;
use App\Support\ManagedWaf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WafController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => ManagedWaf::compile($domain)]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate([
            'profile' => ['required', 'in:off,monitor,balanced,strict'],
            'rules' => ['prohibited'], 'configuration' => ['prohibited'], 'secrule' => ['prohibited'],
        ]);
        DB::transaction(function () use ($data, $domain, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update(['waf_profile' => $data['profile'], 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'waf.profile_updated', $locked, ['profile' => $data['profile'], 'revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain);
    }

    public function exclusions(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $domain->wafExclusions()->with('owner:id,name')->orderByDesc('created_at')->cursorPaginate(100)]);
    }

    public function storeExclusion(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = ManagedWaf::validateExclusion($request->all());
        abort_if($domain->wafExclusions()->where('expires_at', '>', now())->count() >= ManagedWaf::MAXIMUM_EXCLUSIONS, 409, 'The per-domain active WAF-exclusion limit has been reached.');
        $exclusion = DB::transaction(function () use ($data, $domain, $request): WafExclusion {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $exclusion = $locked->wafExclusions()->create([...$data, 'owner_id' => $request->user()->id]);
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'waf.exclusion_created', $exclusion, [
                'dimension' => $exclusion->dimension, 'rule_id' => $exclusion->rule_id,
                'expires_at' => $exclusion->expires_at, 'reason' => $exclusion->reason, 'revision' => $locked->revision,
            ], $request->ip());

            return $exclusion;
        });
        $operation = $this->dispatch($request, $domain);

        return response()->json(['data' => ['exclusion' => $exclusion, 'operation_id' => $operation->id]], 202);
    }

    public function destroyExclusion(Request $request, Domain $domain, WafExclusion $exclusion): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_unless($exclusion->domain_id === $domain->id, 404);
        DB::transaction(function () use ($domain, $exclusion, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            AuditLog::record($request->user(), 'waf.exclusion_deleted', $exclusion, ['revision' => $locked->revision + 1], $request->ip());
            $exclusion->delete();
            $locked->update(['revision' => $locked->revision + 1]);
        });

        return $this->queue($request, $domain);
    }

    private function queue(Request $request, Domain $domain): JsonResponse
    {
        $operation = $this->dispatch($request, $domain);

        return response()->json(['data' => ['profile' => $domain->refresh()->waf_profile, 'operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    private function dispatch(Request $request, Domain $domain): Operation
    {
        $operation = Operation::coalesceDomain('edge.domain_reconcile', $domain->id, $request->user()->id);
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return $operation;
    }
}
