<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformRuleset;
use App\Models\PlatformRulesetRule;
use App\Support\PlatformRulesets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformRulesetController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => PlatformRuleset::query()->withCount(['rules', 'domains'])->orderBy('id')->cursorPaginate(100)]);
    }

    public function show(PlatformRuleset $ruleset): JsonResponse
    {
        return response()->json(['data' => [...$ruleset->loadCount(['rules', 'domains'])->toArray(), 'domain_ids' => $ruleset->domains()->pluck('domains.id')->all()]]);
    }

    public function store(Request $request): JsonResponse
    {
        [$ruleset, $operation] = PlatformRulesets::saveRuleset(null, $this->validated($request), $request->user(), $request->ip());

        return $this->accepted($ruleset, $operation, 201);
    }

    public function update(Request $request, PlatformRuleset $ruleset): JsonResponse
    {
        [$ruleset, $operation] = PlatformRulesets::saveRuleset($ruleset, $this->validated($request, $ruleset), $request->user(), $request->ip());

        return $this->accepted($ruleset, $operation);
    }

    public function destroy(Request $request, PlatformRuleset $ruleset): JsonResponse
    {
        $operation = PlatformRulesets::deleteRuleset($ruleset, $request->user(), $request->ip());

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    public function rules(PlatformRuleset $ruleset): JsonResponse
    {
        return response()->json(['data' => $ruleset->rules()->orderBy('priority')->orderBy('id')->cursorPaginate(100)]);
    }

    public function storeRule(Request $request, PlatformRuleset $ruleset): JsonResponse
    {
        [$rule, $operation] = PlatformRulesets::saveRule($ruleset, null, $request->all(), $request->user(), $request->ip());

        return response()->json(['data' => $rule, 'operation_id' => $operation->id], 201);
    }

    public function updateRule(Request $request, PlatformRuleset $ruleset, PlatformRulesetRule $rule): JsonResponse
    {
        abort_unless($rule->platform_ruleset_id === $ruleset->id, 404);
        [, $operation] = PlatformRulesets::saveRule($ruleset, $rule, $request->all(), $request->user(), $request->ip());

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    public function destroyRule(Request $request, PlatformRuleset $ruleset, PlatformRulesetRule $rule): JsonResponse
    {
        abort_unless($rule->platform_ruleset_id === $ruleset->id, 404);
        $operation = PlatformRulesets::deleteRule($ruleset, $rule, $request->user(), $request->ip());

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    private function validated(Request $request, ?PlatformRuleset $ruleset = null): array
    {
        $required = $ruleset === null ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$required, 'string', 'min:1', 'max:100', Rule::unique('platform_rulesets', 'name')->ignore($ruleset?->id)],
            'description' => ['nullable', 'string', 'max:250'],
            'enabled' => ['sometimes', 'boolean'],
            'applies_to_all' => ['sometimes', 'boolean'],
            'domain_ids' => ['sometimes', 'array', 'max:'.config('security.maximum_platform_ruleset_domains')],
            'domain_ids.*' => ['integer', 'distinct', Rule::exists('domains', 'id')],
        ]);

        return $data;
    }

    private function accepted(PlatformRuleset $ruleset, $operation, int $status = 202): JsonResponse
    {
        return response()->json(['data' => [...$ruleset->only(['id', 'name', 'enabled', 'applies_to_all']), 'operation_id' => $operation->id, 'status' => $operation->status]], $status);
    }
}
