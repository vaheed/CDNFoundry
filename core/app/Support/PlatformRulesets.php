<?php

namespace App\Support;

use App\Jobs\ReconcilePlatformRulesets;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\PlatformRuleset;
use App\Models\PlatformRulesetRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Administrator-defined rulesets of custom and rate-limit rules. The cell runs them for a domain before the domain's own
 * rules. A domain's owner sees which rulesets apply and how often they matched, never what is inside them.
 */
final class PlatformRulesets
{
    /** The rules of every ruleset that applies to the domain, in the order a cell runs them. Rule ids carry a prefix so they never meet a domain rule's id. */
    public static function forDomain(Domain $domain): array
    {
        return PlatformRuleset::query()->appliedTo($domain)->orderBy('id')->with(['rules' => fn ($rules) => $rules->where('enabled', true)->orderBy('priority')->orderBy('id')])->get()
            ->flatMap(fn (PlatformRuleset $ruleset) => $ruleset->rules->map(fn (PlatformRulesetRule $rule): array => [
                'id' => 'p'.$rule->id, 'ruleset' => $ruleset->name, 'name' => $rule->name, 'conditions' => $rule->conditions,
                'rate_limit' => $rule->rate_limit, 'action' => $rule->action, 'priority' => $rule->priority,
            ]))->values()->all();
    }

    /** What a domain owner may see: the names of the rulesets that apply, and nothing about their rules. */
    public static function namesFor(Domain $domain): array
    {
        return PlatformRuleset::query()->appliedTo($domain)->orderBy('name')->get(['id', 'name', 'description'])->map(fn (PlatformRuleset $ruleset): array => $ruleset->only(['id', 'name', 'description']))->all();
    }

    /** The domains a ruleset reaches now: null means every domain. */
    public static function reach(PlatformRuleset $ruleset): ?array
    {
        return $ruleset->applies_to_all ? null : $ruleset->domains()->pluck('domains.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Queues the edge work for a ruleset change: every domain the ruleset reached before or reaches after. The work runs
     * in chunks on the bulk lane and each domain gets its own unique reconcile, so a change never starves interactive work.
     */
    public static function rollOut(?array $before, ?array $after, ?User $actor, string $reason): Operation
    {
        $all = $before === null || $after === null;
        $operation = Operation::query()->create([
            'type' => 'edge.platform_ruleset_reconcile', 'status' => 'pending', 'actor_id' => $actor?->id,
            'input' => ['reason' => $reason, 'all' => $all, 'domain_ids' => $all ? [] : array_values(array_unique([...$before, ...$after]))],
        ]);
        ReconcilePlatformRulesets::dispatch($operation->id)->afterCommit();

        return $operation;
    }

    public static function audit(?User $actor, string $action, PlatformRuleset $ruleset, array $context, ?string $ipAddress): void
    {
        AuditLog::record($actor, $action, $ruleset, ['name' => $ruleset->name, ...$context], $ipAddress);
    }

    public static function inTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
