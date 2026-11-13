<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\EdgePool;
use App\Models\Operation;
use App\Models\WafExclusion;
use App\Support\ManagedWaf;
use App\Support\ManagedWafTuning;
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
            'profile' => ['sometimes', 'required', 'in:off,monitor,balanced,strict'],
            'tuning' => ['sometimes', 'nullable', 'array'],
            'rules' => ['prohibited'], 'configuration' => ['prohibited'], 'secrule' => ['prohibited'],
        ]);
        $profile = $data['profile'] ?? $domain->waf_profile;
        $tuningGiven = array_key_exists('tuning', $data);
        $tuning = $tuningGiven ? ManagedWafTuning::validate($data['tuning'] ?? []) : null;
        if ($profile !== 'off' && ! $this->wafCapablePoolAvailable($domain)) {
            return response()->json([
                'message' => 'No enabled WAF-capable pool can host this domain. An administrator must enable a WAF-capable shared pool first.',
                'code' => 'waf_capable_pool_unavailable', 'details' => (object) [],
            ], 409);
        }
        DB::transaction(function () use ($profile, $tuning, $tuningGiven, $domain, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            Gate::authorize('update', $locked);
            $locked->update(['waf_profile' => $profile, 'revision' => $locked->revision + 1] + ($tuningGiven ? ['waf_tuning' => $tuning] : []));
            AuditLog::record($request->user(), 'waf.profile_updated', $locked, ['profile' => $profile, 'tuned' => $locked->waf_tuning !== null, 'revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain);
    }

    /** The adjustable rules of the pinned ruleset with this domain's state for each: enabled, disabled, or log only. */
    public function rules(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);
        $request->validate(['category' => ['sometimes', 'string'], 'cursor' => ['sometimes', 'integer', 'min:0'], 'search' => ['sometimes', 'string', 'max:100']]);
        $tuning = ManagedWafTuning::normalize($domain->waf_tuning);
        $prefixes = ManagedWafTuning::categoryPrefixes();
        $off = array_map(fn (string $category): string => $prefixes[$category], $tuning['disabled_categories']);
        $limit = 100;
        $rows = collect(ManagedWafTuning::catalog()['rules'])
            ->when($request->query('category'), fn ($rules, $category) => $rules->where('category', $category))
            ->when($request->query('search'), fn ($rules, $search) => $rules->filter(fn (array $rule): bool => str_contains(strtolower($rule['id'].' '.$rule['message']), strtolower($search))))
            ->where('id', '>', (int) $request->query('cursor', 0))->values();
        $page = $rows->take($limit)->map(fn (array $rule): array => [...$rule, 'state' => match (true) {
            in_array(substr((string) $rule['id'], 0, 3), $off, true) || in_array($rule['id'], $tuning['disabled_rules'], true) => 'disabled',
            in_array($rule['id'], $tuning['log_only_rules'], true) => 'log_only',
            default => 'enabled',
        }])->all();

        return response()->json(['data' => $page, 'ruleset' => ManagedWafTuning::catalog()['ruleset'], 'next_cursor' => $rows->count() > $limit ? end($page)['id'] : null]);
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
