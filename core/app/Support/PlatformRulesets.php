<?php

namespace App\Support;

use App\Jobs\ReconcilePlatformRulesets;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\PlatformRuleset;
use App\Models\PlatformRulesetRule;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /** Requests that platform rules matched on the domain in the last 24 hours, from the hourly security events. */
    public static function matchesLast24Hours(Domain $domain): int
    {
        $reasons = ['platform_rule_block', 'platform_rule_challenge', 'platform_rule_log', 'platform_rule_rate_limited'];

        return $domain->securityEvents()->whereIn('reason_code', $reasons)->where('occurred_at', '>=', now()->subDay())->get(['details'])->sum(fn ($event): int => (int) ($event->details['count'] ?? 0));
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

    /**
     * Creates or updates a ruleset and queues the rollout. $data may hold name, description, enabled, applies_to_all, and domain_ids.
     *
     * @return array{0: PlatformRuleset, 1: Operation}
     */
    public static function saveRuleset(?PlatformRuleset $ruleset, array $data, ?User $actor, ?string $ipAddress): array
    {
        if (($data['applies_to_all'] ?? false) === true && ($data['domain_ids'] ?? []) !== []) {
            throw ValidationException::withMessages(['domain_ids' => 'A ruleset that applies to all domains does not list domains.']);
        }
        if (count($data['domain_ids'] ?? []) > config('security.maximum_platform_ruleset_domains')) {
            throw ValidationException::withMessages(['domain_ids' => 'A ruleset may list at most '.config('security.maximum_platform_ruleset_domains').' domains.']);
        }
        abort_if($ruleset === null && PlatformRuleset::query()->count() >= config('security.maximum_platform_rulesets'), 409, 'The platform ruleset limit has been reached.');
        $before = $ruleset === null ? [] : self::reach($ruleset);
        $saved = DB::transaction(function () use ($ruleset, $data, $actor, $ipAddress): PlatformRuleset {
            $values = Arr::only($data, ['name', 'description', 'enabled', 'applies_to_all']);
            $ruleset = $ruleset === null ? PlatformRuleset::query()->create([...$values, 'created_by' => $actor?->id]) : tap($ruleset)->update($values);
            if (array_key_exists('domain_ids', $data)) {
                $ruleset->domains()->sync($data['domain_ids']);
            } elseif ($ruleset->applies_to_all) {
                $ruleset->domains()->detach();
            }
            self::audit($actor, $ruleset->wasRecentlyCreated ? 'platform_ruleset.created' : 'platform_ruleset.updated', $ruleset, ['enabled' => $ruleset->enabled, 'applies_to_all' => $ruleset->applies_to_all], $ipAddress);

            return $ruleset;
        });
        $reach = self::reach($saved->refresh());

        return [$saved, self::rollOut($before, $reach, $actor, $saved->wasRecentlyCreated ? 'ruleset_created' : 'ruleset_updated')];
    }

    public static function deleteRuleset(PlatformRuleset $ruleset, ?User $actor, ?string $ipAddress): Operation
    {
        $before = self::reach($ruleset);
        DB::transaction(function () use ($ruleset, $actor, $ipAddress): void {
            self::audit($actor, 'platform_ruleset.deleted', $ruleset, [], $ipAddress);
            $ruleset->delete();
        });

        return self::rollOut($before, $before === null ? null : [], $actor, 'ruleset_deleted');
    }

    /**
     * Validates and saves one rule of a ruleset (a new rule when $rule is null) and queues the rollout.
     *
     * @return array{0: PlatformRulesetRule, 1: Operation}
     */
    public static function saveRule(PlatformRuleset $ruleset, ?PlatformRulesetRule $rule, array $input, ?User $actor, ?string $ipAddress): array
    {
        $data = SecurityRuleData::validate($input);
        abort_if($rule === null && $ruleset->rules()->count() >= config('security.maximum_platform_rules_per_ruleset'), 409, 'The ruleset already holds the maximum number of rules.');
        if ($ruleset->rules()->where('fingerprint', $data['fingerprint'])->when($rule, fn ($query) => $query->whereKeyNot($rule->id))->exists()) {
            throw ValidationException::withMessages(['conditions' => 'The ruleset already has a rule with the same conditions and action.']);
        }
        $saved = DB::transaction(function () use ($ruleset, $rule, $data, $actor, $ipAddress): PlatformRulesetRule {
            $rule = $rule === null ? $ruleset->rules()->create($data) : tap($rule)->update($data);
            self::audit($actor, $rule->wasRecentlyCreated ? 'platform_ruleset.rule_created' : 'platform_ruleset.rule_updated', $ruleset, ['rule_id' => $rule->id], $ipAddress);

            return $rule;
        });
        $reach = self::reach($ruleset);

        return [$saved, self::rollOut($reach, $reach, $actor, 'rule_saved')];
    }

    public static function deleteRule(PlatformRuleset $ruleset, PlatformRulesetRule $rule, ?User $actor, ?string $ipAddress): Operation
    {
        DB::transaction(function () use ($ruleset, $rule, $actor, $ipAddress): void {
            self::audit($actor, 'platform_ruleset.rule_deleted', $ruleset, ['rule_id' => $rule->id], $ipAddress);
            $rule->delete();
        });
        $reach = self::reach($ruleset);

        return self::rollOut($reach, $reach, $actor, 'rule_deleted');
    }

    public static function audit(?User $actor, string $action, PlatformRuleset $ruleset, array $context, ?string $ipAddress): void
    {
        AuditLog::record($actor, $action, $ruleset, ['name' => $ruleset->name, ...$context], $ipAddress);
    }
}
