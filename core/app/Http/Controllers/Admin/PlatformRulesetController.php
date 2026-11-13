<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformRuleset;
use App\Models\PlatformRulesetRule;
use App\Support\PlatformRulesets;
use App\Support\SecurityRuleData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $data = $this->validated($request);
        abort_if(PlatformRuleset::query()->count() >= config('security.maximum_platform_rulesets'), 409, 'The platform ruleset limit has been reached.');
        $ruleset = PlatformRulesets::inTransaction(function () use ($data, $request): PlatformRuleset {
            $ruleset = PlatformRuleset::query()->create([...Arr::only($data, ['name', 'description', 'enabled', 'applies_to_all']), 'created_by' => $request->user()->id]);
            $ruleset->domains()->sync($data['domain_ids'] ?? []);
            PlatformRulesets::audit($request->user(), 'platform_ruleset.created', $ruleset, ['applies_to_all' => $ruleset->applies_to_all], $request->ip());

            return $ruleset;
        });
        $operation = PlatformRulesets::rollOut($ruleset->applies_to_all ? null : [], PlatformRulesets::reach($ruleset), $request->user(), 'ruleset_created');

        return $this->accepted($ruleset, $operation, 201);
    }

    public function update(Request $request, PlatformRuleset $ruleset): JsonResponse
    {
        $data = $this->validated($request, $ruleset);
        $before = PlatformRulesets::reach($ruleset);
        PlatformRulesets::inTransaction(function () use ($data, $ruleset, $request): void {
            $ruleset->update(Arr::only($data, ['name', 'description', 'enabled', 'applies_to_all']));
            if (array_key_exists('domain_ids', $data)) {
                $ruleset->domains()->sync($data['domain_ids']);
            }
            PlatformRulesets::audit($request->user(), 'platform_ruleset.updated', $ruleset, ['enabled' => $ruleset->enabled, 'applies_to_all' => $ruleset->applies_to_all], $request->ip());
        });
        $operation = PlatformRulesets::rollOut($before, PlatformRulesets::reach($ruleset->refresh()), $request->user(), 'ruleset_updated');

        return $this->accepted($ruleset, $operation);
    }

    public function destroy(Request $request, PlatformRuleset $ruleset): JsonResponse
    {
        $before = PlatformRulesets::reach($ruleset);
        PlatformRulesets::inTransaction(function () use ($ruleset, $request): void {
            PlatformRulesets::audit($request->user(), 'platform_ruleset.deleted', $ruleset, [], $request->ip());
            $ruleset->delete();
        });
        $operation = PlatformRulesets::rollOut($before, $before === null ? null : [], $request->user(), 'ruleset_deleted');

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    public function rules(PlatformRuleset $ruleset): JsonResponse
    {
        return response()->json(['data' => $ruleset->rules()->orderBy('priority')->orderBy('id')->cursorPaginate(100)]);
    }

    public function storeRule(Request $request, PlatformRuleset $ruleset): JsonResponse
    {
        $data = SecurityRuleData::validate($request->all());
        abort_if($ruleset->rules()->count() >= config('security.maximum_platform_rules_per_ruleset'), 409, 'The ruleset already holds the maximum number of rules.');
        $this->refuseDuplicate($ruleset, $data);
        $rule = PlatformRulesets::inTransaction(function () use ($ruleset, $data, $request): PlatformRulesetRule {
            $rule = $ruleset->rules()->create($data);
            PlatformRulesets::audit($request->user(), 'platform_ruleset.rule_created', $ruleset, ['rule_id' => $rule->id], $request->ip());

            return $rule;
        });
        $operation = PlatformRulesets::rollOut(PlatformRulesets::reach($ruleset), PlatformRulesets::reach($ruleset), $request->user(), 'rule_created');

        return response()->json(['data' => $rule, 'operation_id' => $operation->id], 201);
    }

    public function updateRule(Request $request, PlatformRuleset $ruleset, PlatformRulesetRule $rule): JsonResponse
    {
        abort_unless($rule->platform_ruleset_id === $ruleset->id, 404);
        $data = SecurityRuleData::validate($request->all());
        $this->refuseDuplicate($ruleset, $data, $rule);
        PlatformRulesets::inTransaction(function () use ($ruleset, $rule, $data, $request): void {
            $rule->update($data);
            PlatformRulesets::audit($request->user(), 'platform_ruleset.rule_updated', $ruleset, ['rule_id' => $rule->id], $request->ip());
        });
        $operation = PlatformRulesets::rollOut(PlatformRulesets::reach($ruleset), PlatformRulesets::reach($ruleset), $request->user(), 'rule_updated');

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    public function destroyRule(Request $request, PlatformRuleset $ruleset, PlatformRulesetRule $rule): JsonResponse
    {
        abort_unless($rule->platform_ruleset_id === $ruleset->id, 404);
        PlatformRulesets::inTransaction(function () use ($ruleset, $rule, $request): void {
            PlatformRulesets::audit($request->user(), 'platform_ruleset.rule_deleted', $ruleset, ['rule_id' => $rule->id], $request->ip());
            $rule->delete();
        });
        $operation = PlatformRulesets::rollOut(PlatformRulesets::reach($ruleset), PlatformRulesets::reach($ruleset), $request->user(), 'rule_deleted');

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
        if (($data['applies_to_all'] ?? false) === true && ($data['domain_ids'] ?? []) !== []) {
            throw ValidationException::withMessages(['domain_ids' => 'A ruleset that applies to all domains does not list domains.']);
        }

        return $data;
    }

    private function refuseDuplicate(PlatformRuleset $ruleset, array $data, ?PlatformRulesetRule $except = null): void
    {
        if ($ruleset->rules()->where('fingerprint', $data['fingerprint'])->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists()) {
            throw ValidationException::withMessages(['conditions' => 'The ruleset already has a rule with the same conditions and action.']);
        }
    }

    private function accepted(PlatformRuleset $ruleset, $operation, int $status = 202): JsonResponse
    {
        return response()->json(['data' => [...$ruleset->only(['id', 'name', 'enabled', 'applies_to_all']), 'operation_id' => $operation->id, 'status' => $operation->status]], $status);
    }
}
