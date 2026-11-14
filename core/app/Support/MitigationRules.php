<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\PlatformRulesetRule;

/** Names the rule behind a mitigated request. A platform rule is named by its ruleset only; its conditions are never shown to a domain owner. */
final class MitigationRules
{
    /**
     * @param  list<string>  $refs  rule references from request events: "r12", "p5", or "waf"
     * @return array<string, array{type: string, label: string, priority: ?int, action: ?string}>
     */
    public static function describe(Domain $domain, array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs)));
        $domainIds = collect($refs)->filter(fn (string $ref): bool => preg_match('/^r\d+$/', $ref) === 1)->map(fn (string $ref): int => (int) substr($ref, 1))->all();
        $platformIds = collect($refs)->filter(fn (string $ref): bool => preg_match('/^p\d+$/', $ref) === 1)->map(fn (string $ref): int => (int) substr($ref, 1))->all();
        $domainRules = $domainIds === [] ? collect() : $domain->securityRules()->whereIn('id', $domainIds)->get()->keyBy('id');
        $platformRules = $platformIds === [] ? collect() : PlatformRulesetRule::query()->with('ruleset:id,name')->whereIn('id', $platformIds)->get()->keyBy('id');
        $described = [];
        foreach ($refs as $ref) {
            $described[$ref] = match (true) {
                $ref === 'waf' => ['type' => 'managed', 'label' => 'Managed WAF', 'priority' => null, 'action' => null],
                isset($domainRules[(int) substr($ref, 1)]) && $ref[0] === 'r' => (function () use ($domainRules, $ref): array {
                    $rule = $domainRules[(int) substr($ref, 1)];

                    return ['type' => 'domain', 'label' => $rule->name ?? SecurityRuleData::describe($rule->conditions), 'priority' => $rule->priority, 'action' => $rule->action];
                })(),
                isset($platformRules[(int) substr($ref, 1)]) && $ref[0] === 'p' => ['type' => 'platform', 'label' => 'Platform ruleset: '.$platformRules[(int) substr($ref, 1)]->ruleset?->name, 'priority' => null, 'action' => null],
                default => ['type' => 'removed', 'label' => 'A rule that no longer exists', 'priority' => null, 'action' => null],
            };
        }

        return $described;
    }
}
