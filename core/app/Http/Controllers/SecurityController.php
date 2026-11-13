<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\SecurityRule;
use App\Support\PlatformRulesets;
use App\Support\SecurityConfig;
use App\Support\SecurityRuleData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $this->settings($domain)]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $settings = SecurityConfig::validateSettings($request->all());
        DB::transaction(function () use ($domain, $request, $settings): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->update(['security_settings' => $settings, 'revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'security.settings_updated', $locked, ['profile' => $settings['profile'], 'revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain);
    }

    /** The platform rulesets that apply to the domain. Their rules are never shown to a domain owner. */
    public function platformRulesets(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => ['rulesets' => PlatformRulesets::namesFor($domain), 'matches_last_24_hours' => PlatformRulesets::matchesLast24Hours($domain)]]);
    }

    public function rules(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $domain->securityRules()->orderBy('priority')->orderBy('id')->cursorPaginate(100)]);
    }

    public function storeRule(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = SecurityRuleData::validate($request->all());
        abort_if($domain->securityRules()->count() >= config('security.maximum_rules_per_domain'), 409, 'The per-domain security-rule limit has been reached.');
        $this->refuseDuplicate($domain, $data);
        SecurityRuleData::assertRateLimitCapacity($domain, $data['rate_limit'] === null ? 0 : 1);
        $rule = DB::transaction(function () use ($data, $domain, $request): SecurityRule {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $rule = $locked->securityRules()->create($data);
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'security.rule_created', $rule, ['revision' => $locked->revision], $request->ip());

            return $rule;
        });
        $this->dispatch($request, $domain);

        return response()->json(['data' => $rule], 201);
    }

    public function updateRule(Request $request, Domain $domain, SecurityRule $rule): JsonResponse
    {
        $this->rule($domain, $rule);
        Gate::authorize('update', $domain);
        $data = SecurityRuleData::validate($request->all());
        $this->refuseDuplicate($domain, $data, $rule);
        SecurityRuleData::assertRateLimitCapacity($domain, $data['rate_limit'] === null ? 0 : 1, false, $rule->id);
        DB::transaction(function () use ($data, $domain, $request, $rule): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            // A preset rule edited by hand is an ordinary rule from then on, so the preset never describes a rule it did not write.
            $rule->update([...$data, 'preset' => null]);
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'security.rule_updated', $rule, ['revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain);
    }

    public function destroyRule(Request $request, Domain $domain, SecurityRule $rule): JsonResponse
    {
        $this->rule($domain, $rule);
        Gate::authorize('update', $domain);
        DB::transaction(function () use ($domain, $request, $rule): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $id = $rule->id;
            $rule->delete();
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'security.rule_deleted', $locked, ['rule_id' => $id, 'revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain);
    }

    /** Checks a rule without saving it: returns the normalized rule and the position it would take in the evaluation order. */
    public function previewRule(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = SecurityRuleData::validate($request->all());
        $position = $domain->securityRules()->where('enabled', true)->where('priority', '<=', $data['priority'])->count() + 1;
        $duplicate = $domain->securityRules()->where('fingerprint', $data['fingerprint'])->value('id');

        return response()->json(['data' => [
            'rule' => Arr::only($data, ['name', 'conditions', 'rate_limit', 'action', 'priority', 'enabled', 'note']),
            'position' => $data['enabled'] ? $position : null, 'duplicate_of' => $duplicate,
            'rules_in_use' => $domain->securityRules()->count(), 'maximum_rules' => config('security.maximum_rules_per_domain'),
        ]]);
    }

    /** Sets the evaluation order: the listed rule ids, first to last, must be exactly the domain's rules. */
    public function reorderRules(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate(['order' => ['required', 'array', 'min:1', 'max:'.config('security.maximum_rules_per_domain')], 'order.*' => ['required', 'integer', 'distinct']]);
        DB::transaction(function () use ($data, $domain, $request): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $existing = $locked->securityRules()->pluck('id')->all();
            if (array_diff($existing, $data['order']) !== [] || array_diff($data['order'], $existing) !== []) {
                throw ValidationException::withMessages(['order' => 'The order must list every rule of the domain exactly once.']);
            }
            foreach (array_values($data['order']) as $index => $id) {
                $locked->securityRules()->whereKey($id)->update(['priority' => ($index + 1) * 10]);
            }
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'security.rules_reordered', $locked, ['count' => count($data['order']), 'revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain);
    }

    public function importRules(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1', 'max:'.config('security.maximum_import_rules')],
            'rules.*' => ['required', 'array'], 'replace_existing' => ['sometimes', 'boolean'],
        ]);
        $rules = collect($data['rules'])->map(fn (array $rule): array => SecurityRuleData::validate($rule));
        $duplicates = $rules->pluck('fingerprint')->duplicates();
        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages(['rules' => 'The import contains duplicate normalized rules.']);
        }
        $replace = (bool) ($data['replace_existing'] ?? false);
        abort_if(! $replace && $domain->securityRules()->count() + $rules->count() > config('security.maximum_rules_per_domain'), 409, 'The import would exceed the per-domain security-rule limit.');
        SecurityRuleData::assertRateLimitCapacity($domain, $rules->whereNotNull('rate_limit')->count(), $replace);
        DB::transaction(function () use ($domain, $replace, $request, $rules): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($replace) {
                $locked->securityRules()->delete();
            }
            $now = now();
            $locked->securityRules()->insert($rules->map(fn (array $rule): array => [...SecurityRuleData::row($rule), 'domain_id' => $locked->id, 'created_at' => $now, 'updated_at' => $now])->all());
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($request->user(), 'security.rules_imported', $locked, ['count' => $rules->count(), 'replace_existing' => $replace, 'revision' => $locked->revision], $request->ip());
        });

        return $this->queue($request, $domain);
    }

    public function ddos(Domain $domain): JsonResponse
    {
        return $this->show($domain);
    }

    public function updateDdos(Request $request, Domain $domain): JsonResponse
    {
        return $this->update($request, $domain);
    }

    public function status(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => [
            'profile' => $this->settings($domain)['profile'], 'state' => $domain->effectiveSecurityState(),
            'configured_state' => $domain->security_state, 'automatic_state' => $domain->automatic_security_state,
            'state_changed_at' => $domain->security_state_changed_at,
            'placement' => $domain->edgePlacement?->load(['activePool', 'targetPool']),
        ]]);
    }

    public function events(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $domain->securityEvents()->latest('occurred_at')->latest('id')->cursorPaginate(100)]);
    }

    private function settings(Domain $domain): array
    {
        $settings = is_array($domain->security_settings) ? $domain->security_settings : SecurityConfig::defaults();

        return [...$settings, 'platform_ceilings' => SecurityConfig::ceilingLimits($settings['profile']), 'maximum_rules' => config('security.maximum_rules_per_domain')];
    }

    private function queue(Request $request, Domain $domain): JsonResponse
    {
        $operation = $this->dispatch($request, $domain);

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    private function dispatch(Request $request, Domain $domain): Operation
    {
        $operation = Operation::coalesceDomain('edge.domain_reconcile', $domain->id, $request->user()->id);
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return $operation;
    }

    private function refuseDuplicate(Domain $domain, array $data, ?SecurityRule $except = null): void
    {
        $duplicate = $domain->securityRules()->where('fingerprint', $data['fingerprint'])->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['conditions' => 'The domain already has a rule with the same conditions and action.']);
        }
    }

    private function rule(Domain $domain, SecurityRule $rule): void
    {
        abort_unless($rule->domain_id === $domain->id, 404);
    }
}
