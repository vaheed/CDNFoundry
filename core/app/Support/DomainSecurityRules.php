<?php

namespace App\Support;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\SecurityRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creating one custom rule of a domain: the validation, limits, revision, audit entry, and queued edge work every entry point shares. */
final class DomainSecurityRules
{
    public static function create(Domain $domain, array $input, ?User $actor, ?string $ipAddress): SecurityRule
    {
        $data = SecurityRuleData::validate($input);
        SecurityRuleData::assertFieldsAvailable($domain, $data['conditions']);
        abort_if($domain->securityRules()->count() >= config('security.maximum_rules_per_domain'), 409, 'The per-domain security-rule limit has been reached.');
        if ($domain->securityRules()->where('fingerprint', $data['fingerprint'])->exists()) {
            throw ValidationException::withMessages(['conditions' => 'The domain already has a rule with the same conditions and action.']);
        }
        SecurityRuleData::assertRateLimitCapacity($domain, $data['rate_limit'] === null ? 0 : 1);
        $rule = DB::transaction(function () use ($data, $domain, $actor, $ipAddress): SecurityRule {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $rule = $locked->securityRules()->create($data);
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($actor, 'security.rule_created', $rule, ['revision' => $locked->revision], $ipAddress);

            return $rule;
        });
        self::queue($domain, $actor);

        return $rule;
    }

    public static function queue(Domain $domain, ?User $actor): Operation
    {
        $operation = Operation::coalesceDomain('edge.domain_reconcile', $domain->id, $actor?->id);
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();

        return $operation;
    }
}
