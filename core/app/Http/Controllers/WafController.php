<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\EdgePool;
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
        if ($data['profile'] !== 'off' && ! $this->wafCapablePoolAvailable($domain)) {
            return response()->json([
                'message' => 'No enabled WAF-capable pool can host this domain. An administrator must enable a WAF-capable shared pool first.',
                'code' => 'waf_capable_pool_unavailable', 'details' => (object) [],
            ], 409);
        }
        DB::transaction(function () use ($data, $domain, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            Gate::authorize('update', $locked);
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

    public function storeExclusion(Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);

        return response()->json([
            'message' => 'WAF exclusions are unavailable: the runtime cannot yet remove one rule for one target without weakening the whole request.',
            'code' => 'waf_exclusions_unavailable', 'details' => (object) [],
        ], 409);
    }

    public function destroyExclusion(Request $request, Domain $domain, WafExclusion $exclusion): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_unless($exclusion->domain_id === $domain->id, 404);
        DB::transaction(function () use ($domain, $exclusion, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            Gate::authorize('update', $locked);
            abort_unless($exclusion->domain_id === $locked->id && $exclusion->exists, 404);
            AuditLog::record($request->user(), 'waf.exclusion_deleted', $exclusion, ['revision' => $locked->revision + 1], $request->ip());
            $exclusion->delete();
            $locked->update(['revision' => $locked->revision + 1]);
        });

        return $this->queue($request, $domain);
    }

    private function wafCapablePoolAvailable(Domain $domain): bool
    {
        $placement = $domain->edgePlacement ?? null;
        $currentPoolId = $placement?->target_pool_id ?? $placement?->active_pool_id;

        return EdgePool::query()->where('enabled', true)->where('withdrawn', false)->where('waf_capable', true)
            ->where(fn ($query) => $query->where('kind', 'shared')->when($currentPoolId !== null, fn ($q) => $q->orWhere('id', $currentPoolId)))
            ->exists();
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
