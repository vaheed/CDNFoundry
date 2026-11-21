<?php

namespace App\Support;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\RequestRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The writes to a domain's request rules that the API and the domain panel share: the validation, the limit per type, the
 * revision, the audit entry, and the queued edge work. Every write raises the domain revision once.
 */
final class DomainRequestRules
{
    /** @return array{0: RequestRule, 1: Operation} */
    public static function create(Domain $domain, array $input, ?User $actor, ?string $ipAddress): array
    {
        $data = RequestRuleData::validate($input);
        $rule = DB::transaction(function () use ($data, $domain, $actor, $ipAddress): RequestRule {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            abort_if($locked->requestRules()->where('type', $data['type'])->count() >= RequestRuleData::maximumRulesPerType(), 409,
                'The domain already has '.RequestRuleData::maximumRulesPerType().' rules of this type.');
            self::refuseDuplicate($locked, $data);
            $rule = $locked->requestRules()->create($data);
            self::changed($locked, $actor, 'request_rule.created', $rule, $ipAddress);

            return $rule;
        });

        return [$rule, self::queue($domain, $actor)];
    }

    public static function update(Domain $domain, RequestRule $rule, array $input, ?User $actor, ?string $ipAddress): Operation
    {
        $data = RequestRuleData::validate([...$input, 'type' => $rule->type]);
        DB::transaction(function () use ($data, $domain, $rule, $actor, $ipAddress): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            self::refuseDuplicate($locked, $data, $rule);
            // A preset rule edited by hand is an ordinary rule from then on, so the preset never describes a rule it did not write.
            $rule->update([...$data, 'preset' => null]);
            self::changed($locked, $actor, 'request_rule.updated', $rule, $ipAddress);
        });

        return self::queue($domain, $actor);
    }

    public static function delete(Domain $domain, RequestRule $rule, ?User $actor, ?string $ipAddress): Operation
    {
        DB::transaction(function () use ($domain, $rule, $actor, $ipAddress): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $id = $rule->id;
            $rule->delete();
            self::changed($locked, $actor, 'request_rule.deleted', $locked, $ipAddress, ['rule_id' => $id]);
        });

        return self::queue($domain, $actor);
    }

    /** Sets the evaluation order of one type: the listed ids, first to last, must be exactly the domain's rules of that type. */
    public static function reorder(Domain $domain, string $type, array $order, ?User $actor, ?string $ipAddress): Operation
    {
        DB::transaction(function () use ($domain, $type, $order, $actor, $ipAddress): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $existing = $locked->requestRules()->where('type', $type)->pluck('id')->all();
            if (count($order) !== count($existing) || array_diff($existing, $order) !== [] || array_diff($order, $existing) !== []) {
                throw ValidationException::withMessages(['order' => 'The order must list every rule of this type exactly once.']);
            }
            foreach (array_values($order) as $index => $id) {
                $locked->requestRules()->whereKey($id)->update(['priority' => ($index + 1) * 10]);
            }
            self::changed($locked, $actor, 'request_rule.reordered', $locked, $ipAddress, ['type' => $type, 'count' => count($order)]);
        });

        return self::queue($domain, $actor);
    }

    /** Moves one rule a place up (-1) or down (1) within its type. */
    public static function move(Domain $domain, RequestRule $rule, int $direction, ?User $actor, ?string $ipAddress): ?Operation
    {
        $ids = $domain->requestRules()->where('type', $rule->type)->orderBy('priority')->orderBy('id')->pluck('id')->all();
        $index = array_search($rule->id, $ids, true);
        if ($index === false || ! isset($ids[$index + $direction])) {
            return null;
        }
        [$ids[$index], $ids[$index + $direction]] = [$ids[$index + $direction], $ids[$index]];

        return self::reorder($domain, $rule->type, $ids, $actor, $ipAddress);
    }

    /** Replaces a preset's rules in one revision. Null removes the preset. */
    public static function replacePreset(Domain $domain, string $preset, ?array $rules, ?User $actor, ?string $ipAddress): Operation
    {
        DB::transaction(function () use ($domain, $preset, $rules, $actor, $ipAddress): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->requestRules()->where('preset', $preset)->delete();
            $now = now();
            $rows = [];
            foreach ($rules ?? [] as $rule) {
                $existing = $locked->requestRules()->where('type', $rule['type'])->count() + count(array_filter($rows, fn (array $row): bool => $row['type'] === $rule['type']));
                abort_if($existing >= RequestRuleData::maximumRulesPerType(), 409, 'The preset would exceed '.RequestRuleData::maximumRulesPerType().' rules of this type.');
                self::refuseDuplicate($locked, $rule);
                $rows[] = [...RequestRuleData::row($rule), 'preset' => $preset, 'domain_id' => $locked->id, 'created_at' => $now, 'updated_at' => $now];
            }
            if ($rows !== []) {
                $locked->requestRules()->insert($rows);
            }
            self::changed($locked, $actor, $rules === null ? 'request_rule.preset_removed' : 'request_rule.preset_saved', $locked, $ipAddress, ['preset' => $preset, 'rules' => count($rows)]);
        });

        return self::queue($domain, $actor);
    }

    public static function queue(Domain $domain, ?User $actor): Operation
    {
        $operation = Operation::coalesceDomain('edge.domain_reconcile', $domain->id, $actor?->id);
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return $operation;
    }

    private static function changed(Domain $locked, ?User $actor, string $action, object $subject, ?string $ipAddress, array $extra = []): void
    {
        $locked->update(['revision' => $locked->revision + 1]);
        AuditLog::record($actor, $action, $subject, [...$extra, 'domain_id' => $locked->id, 'revision' => $locked->revision], $ipAddress);
    }

    private static function refuseDuplicate(Domain $domain, array $data, ?RequestRule $except = null): void
    {
        $duplicate = $domain->requestRules()->where('type', $data['type'])->where('fingerprint', $data['fingerprint'])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['conditions' => 'The domain already has a rule with the same conditions and action.']);
        }
    }
}
