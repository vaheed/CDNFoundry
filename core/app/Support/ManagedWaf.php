<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManagedWaf
{
    public const PROFILES = [
        'off' => ['paranoia_level' => 0, 'inbound_threshold' => 0, 'outbound_threshold' => 0, 'body_limit_bytes' => 0, 'blocking' => false],
        'monitor' => ['paranoia_level' => 1, 'inbound_threshold' => 5, 'outbound_threshold' => 4, 'body_limit_bytes' => 1048576, 'blocking' => false],
        'balanced' => ['paranoia_level' => 1, 'inbound_threshold' => 5, 'outbound_threshold' => 4, 'body_limit_bytes' => 1048576, 'blocking' => true],
        'strict' => ['paranoia_level' => 2, 'inbound_threshold' => 3, 'outbound_threshold' => 3, 'body_limit_bytes' => 262144, 'blocking' => true],
    ];

    public const MAXIMUM_EXCLUSIONS = 50;

    public static function profile(string $name): array
    {
        return ['name' => $name, ...(self::PROFILES[$name] ?? self::PROFILES['off'])];
    }

    public static function compile(Domain $domain): array
    {
        return [
            ...self::profile($domain->waf_profile),
            'ruleset' => config('security.waf.ruleset'),
            'exclusions' => $domain->wafExclusions()->where('expires_at', '>', now())->orderBy('id')
                ->get(['id', 'dimension', 'value', 'rule_id', 'expires_at'])
                ->map(fn ($row): array => [
                    'id' => $row->id, 'dimension' => $row->dimension, 'value' => $row->value,
                    'rule_id' => $row->rule_id, 'expires_at' => $row->expires_at->timestamp,
                ])->all(),
        ];
    }

    public static function validateExclusion(array $input): array
    {
        $data = validator($input, [
            'dimension' => ['required', 'in:path,rule,parameter,cookie'],
            'value' => ['required', 'string', 'max:255'],
            'rule_id' => ['nullable', 'integer', 'between:900000,999999'],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
            'expires_at' => ['required', 'date', 'after:now', 'before_or_equal:'.now()->addDays(30)->toIso8601String()],
            'secrule' => ['prohibited'], 'rules' => ['prohibited'], 'configuration' => ['prohibited'],
        ])->validate();
        $value = trim($data['value']);
        if ($data['dimension'] === 'path' && (! Str::startsWith($value, '/') || Str::contains($value, ['*', '?', '[', ']', '{', '}']))) {
            throw ValidationException::withMessages(['value' => 'A path exclusion must be one literal absolute path without patterns.']);
        }
        if (in_array($data['dimension'], ['parameter', 'cookie'], true) && ! preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $value)) {
            throw ValidationException::withMessages(['value' => 'Parameter and cookie exclusions use one literal approved name.']);
        }
        if ($data['dimension'] === 'rule' && empty($data['rule_id'])) {
            throw ValidationException::withMessages(['rule_id' => 'A bounded CRS rule ID is required for a rule exclusion.']);
        }

        return [...$data, 'value' => $value];
    }
}
