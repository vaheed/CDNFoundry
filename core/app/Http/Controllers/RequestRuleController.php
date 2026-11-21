<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Operation;
use App\Models\RequestRule;
use App\Support\DomainRequestRules;
use App\Support\RequestRuleData;
use App\Support\RequestRulePresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** A domain's request rules: redirects decided in the cell from the signed snapshot, never by the control plane. */
class RequestRuleController extends Controller
{
    public function index(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);
        $data = $request->validate(['type' => ['sometimes', Rule::in(RequestRuleData::TYPES)]]);

        return response()->json(['data' => $domain->requestRules()->when($data['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->orderBy('type')->orderBy('priority')->orderBy('id')->cursorPaginate(100)]);
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        [$rule, $operation] = DomainRequestRules::create($domain, $request->all(), $request->user(), $request->ip());

        return response()->json(['data' => [...$rule->toArray(), 'operation_id' => $operation->id, 'status' => $operation->status]], 201);
    }

    public function update(Request $request, Domain $domain, RequestRule $rule): JsonResponse
    {
        $this->owned($domain, $rule);
        Gate::authorize('update', $domain);

        return $this->accepted(DomainRequestRules::update($domain, $rule, $request->all(), $request->user(), $request->ip()));
    }

    public function destroy(Request $request, Domain $domain, RequestRule $rule): JsonResponse
    {
        $this->owned($domain, $rule);
        Gate::authorize('update', $domain);

        return $this->accepted(DomainRequestRules::delete($domain, $rule, $request->user(), $request->ip()));
    }

    /** Sets the evaluation order of one type: the listed ids, first to last, must be exactly the domain's rules of that type. */
    public function reorder(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate([
            'type' => ['required', Rule::in(RequestRuleData::TYPES)],
            'order' => ['required', 'array', 'min:1', 'max:'.RequestRuleData::maximumRulesPerType()], 'order.*' => ['required', 'integer', 'distinct'],
        ]);

        return $this->accepted(DomainRequestRules::reorder($domain, $data['type'], array_map('intval', $data['order']), $request->user(), $request->ip()));
    }

    public function savePreset(Request $request, Domain $domain, string $preset): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_unless(isset(RequestRulePresets::PRESETS[$preset]), 404);

        return $this->accepted(DomainRequestRules::replacePreset($domain, $preset, RequestRulePresets::rules($domain, $preset, $request->all()), $request->user(), $request->ip()));
    }

    public function destroyPreset(Request $request, Domain $domain, string $preset): JsonResponse
    {
        Gate::authorize('update', $domain);
        abort_unless(isset(RequestRulePresets::PRESETS[$preset]), 404);

        return $this->accepted(DomainRequestRules::replacePreset($domain, $preset, null, $request->user(), $request->ip()));
    }

    private function accepted(Operation $operation): JsonResponse
    {
        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    private function owned(Domain $domain, RequestRule $rule): void
    {
        abort_unless($rule->domain_id === $domain->id, 404);
    }
}
