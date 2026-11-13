<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Two presets over the typed rules: blocking or challenging user agents, and hotlink protection. A preset is nothing but
 * ordinary custom rules marked with their preset, so the rule engine, order, limits, and revisions stay the ones every rule uses.
 */
final class SecurityPresets
{
    public const USER_AGENT = 'user_agent';

    public const HOTLINK = 'hotlink';

    public const MAXIMUM_USER_AGENTS = 50;

    public const MAXIMUM_EXTENSIONS = 50;

    public const MAXIMUM_HOSTS = 48;

    /** Preset rules sit after the ones a person numbers by hand. */
    private const USER_AGENT_PRIORITY = 500;

    private const HOTLINK_PRIORITY = 400;

    /** @return list<array> one validated rule per user agent value */
    public static function userAgentRules(array $input): array
    {
        $data = Validator::make($input, [
            'values' => ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_USER_AGENTS],
            'values.*' => ['required', 'string', 'min:1', 'max:100'],
            'action' => ['required', Rule::in(['block', 'challenge'])],
        ])->validate();
        $seen = [];
        $rules = [];
        foreach ($data['values'] as $value) {
            $value = trim($value);
            if ($value === '' || isset($seen[strtolower($value)])) {
                continue;
            }
            $seen[strtolower($value)] = true;
            $rules[] = SecurityRuleData::validate([
                'name' => mb_substr("User agent: $value", 0, 100), 'action' => $data['action'], 'priority' => self::USER_AGENT_PRIORITY + count($rules),
                'conditions' => [['field' => 'user_agent', 'operator' => 'contains', 'value' => $value]],
            ]) + ['preset' => self::USER_AGENT];
        }
        if ($rules === []) {
            throw ValidationException::withMessages(['values' => 'Add at least one user agent.']);
        }

        return $rules;
    }

    /** @return list<array> the single rule that blocks files on other sites' pages */
    public static function hotlinkRules(Domain $domain, array $input): array
    {
        $data = Validator::make($input, [
            'extensions' => ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_EXTENSIONS],
            'extensions.*' => ['required', 'string', 'max:10'],
            'allowed_hosts' => ['nullable', 'array', 'max:'.self::MAXIMUM_HOSTS],
            'allowed_hosts.*' => ['required', 'string', 'max:253'],
        ])->validate();
        // The domain's own name and its www form are always allowed, and a request with no Referer is never blocked.
        $hosts = array_values(array_unique([...array_map(fn (string $host): string => strtolower(trim($host)), $data['allowed_hosts'] ?? []), strtolower($domain->name), 'www.'.strtolower($domain->name)]));
        $extensions = array_values(array_unique(array_map(fn (string $extension): string => strtolower(ltrim(trim($extension), '.')), $data['extensions'])));

        return [SecurityRuleData::validate([
            'name' => 'Hotlink protection', 'action' => 'block', 'priority' => self::HOTLINK_PRIORITY,
            'conditions' => [
                ['field' => 'file_extension', 'operator' => 'in_list', 'value' => $extensions],
                ['field' => 'referer_present', 'operator' => 'equals', 'value' => true],
                ['field' => 'referer_host', 'operator' => 'not_in_list', 'value' => $hosts],
            ],
        ]) + ['preset' => self::HOTLINK]];
    }

    /** The preset as the user last saved it, or null when it is not set. */
    public static function current(Domain $domain, string $preset): ?array
    {
        $rules = $domain->securityRules()->where('preset', $preset)->orderBy('priority')->orderBy('id')->get();
        if ($rules->isEmpty()) {
            return null;
        }
        if ($preset === self::USER_AGENT) {
            return ['values' => $rules->map(fn ($rule): string => (string) $rule->conditions[0]['value'])->all(), 'action' => $rules->first()->action, 'enabled' => $rules->every->enabled];
        }
        $conditions = $rules->first()->conditions;
        $own = [strtolower($domain->name), 'www.'.strtolower($domain->name)];

        return ['extensions' => $conditions[0]['value'], 'allowed_hosts' => array_values(array_diff($conditions[2]['value'], $own)), 'enabled' => (bool) $rules->first()->enabled];
    }

    /**
     * Replaces a preset's rules in one desired revision. Null removes the preset. The caller queues the edge work.
     */
    public static function replace(Domain $domain, string $preset, ?array $rules, ?User $actor, ?string $ipAddress): void
    {
        DB::transaction(function () use ($domain, $preset, $rules, $actor, $ipAddress): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $locked->securityRules()->where('preset', $preset)->delete();
            if ($rules !== null) {
                abort_if($locked->securityRules()->count() + count($rules) > config('security.maximum_rules_per_domain'), 409, 'The preset would exceed the per-domain security-rule limit.');
                $taken = $locked->securityRules()->pluck('fingerprint')->flip();
                $now = now();
                $rows = [];
                foreach ($rules as $rule) {
                    if (! isset($taken[$rule['fingerprint']])) {
                        $rows[] = [...SecurityRuleData::row($rule), 'domain_id' => $locked->id, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                $locked->securityRules()->insert($rows);
            }
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record($actor, $rules === null ? 'security.preset_removed' : 'security.preset_updated', $locked, ['preset' => $preset, 'rules' => count($rules ?? []), 'revision' => $locked->revision], $ipAddress);
        });
    }
}
