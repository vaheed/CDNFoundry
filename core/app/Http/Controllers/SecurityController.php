<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\SecurityRule;
use App\Support\SecurityConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function rules(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);

        return response()->json(['data' => $domain->securityRules()->orderBy('priority')->orderBy('id')->cursorPaginate(100)]);
    }

    public function storeRule(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = SecurityConfig::validateRule($request->all());
        abort_if($domain->securityRules()->count() >= config('security.maximum_rules_per_domain'), 409, 'The per-domain security-rule limit has been reached.');
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
        $data = SecurityConfig::validateRule($request->all());
        DB::transaction(function () use ($data, $domain, $request, $rule): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $rule->update($data);
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

    public function importRules(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);
        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1', 'max:'.config('security.maximum_import_rules')],
            'rules.*' => ['required', 'array'], 'replace_existing' => ['sometimes', 'boolean'],
        ]);
        $rules = collect($data['rules'])->map(fn (array $rule): array => SecurityConfig::validateRule($rule));
        $duplicates = $rules->map(fn (array $rule): string => implode('|', [$rule['match_type'], $rule['value'], $rule['action']]))->duplicates();
        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages(['rules' => 'The import contains duplicate normalized rules.']);
        }
        $replace = (bool) ($data['replace_existing'] ?? false);
        abort_if(! $replace && $domain->securityRules()->count() + $rules->count() > config('security.maximum_rules_per_domain'), 409, 'The import would exceed the per-domain security-rule limit.');
        DB::transaction(function () use ($domain, $replace, $request, $rules): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($replace) {
                $locked->securityRules()->delete();
            }
            $now = now();
            $locked->securityRules()->insert($rules->map(fn (array $rule): array => [...$rule, 'domain_id' => $locked->id, 'created_at' => $now, 'updated_at' => $now])->all());
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
            'profile' => $this->settings($domain)['profile'], 'state' => $domain->security_state,
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

        return [...$settings, 'platform_ceilings' => config("security.profiles.{$settings['profile']}"), 'maximum_rules' => config('security.maximum_rules_per_domain')];
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

    private function rule(Domain $domain, SecurityRule $rule): void
    {
        abort_unless($rule->domain_id === $domain->id, 404);
    }
}
