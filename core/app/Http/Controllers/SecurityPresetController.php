<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\Operation;
use App\Support\SecurityPresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SecurityPresetController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => [
            'user_agent' => SecurityPresets::current($domain, SecurityPresets::USER_AGENT),
            'hotlink' => SecurityPresets::current($domain, SecurityPresets::HOTLINK),
        ]]);
    }

    public function updateUserAgent(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        SecurityPresets::replace($domain, SecurityPresets::USER_AGENT, SecurityPresets::userAgentRules($request->all()), $request->user(), $request->ip());

        return $this->queue($request, $domain);
    }

    public function destroyUserAgent(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        SecurityPresets::replace($domain, SecurityPresets::USER_AGENT, null, $request->user(), $request->ip());

        return $this->queue($request, $domain);
    }

    public function updateHotlink(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        SecurityPresets::replace($domain, SecurityPresets::HOTLINK, SecurityPresets::hotlinkRules($domain, $request->all()), $request->user(), $request->ip());

        return $this->queue($request, $domain);
    }

    public function destroyHotlink(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        SecurityPresets::replace($domain, SecurityPresets::HOTLINK, null, $request->user(), $request->ip());

        return $this->queue($request, $domain);
    }

    private function queue(Request $request, Domain $domain): JsonResponse
    {
        $operation = Operation::coalesceDomain('edge.domain_reconcile', $domain->id, $request->user()->id);
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }
}
