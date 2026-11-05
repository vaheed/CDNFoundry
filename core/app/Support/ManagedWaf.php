<?php

namespace App\Support;

use App\Models\Domain;

final class ManagedWaf
{
    public const PROFILES = [
        'off' => ['paranoia_level' => 0, 'inbound_threshold' => 0, 'outbound_threshold' => 0, 'body_limit_bytes' => 0, 'blocking' => false],
        'monitor' => ['paranoia_level' => 1, 'inbound_threshold' => 5, 'outbound_threshold' => 4, 'body_limit_bytes' => 1048576, 'blocking' => false],
        'balanced' => ['paranoia_level' => 1, 'inbound_threshold' => 5, 'outbound_threshold' => 4, 'body_limit_bytes' => 1048576, 'blocking' => true],
        'strict' => ['paranoia_level' => 2, 'inbound_threshold' => 3, 'outbound_threshold' => 3, 'body_limit_bytes' => 262144, 'blocking' => true],
    ];

    public static function profile(string $name): array
    {
        return ['name' => $name, ...(self::PROFILES[$name] ?? self::PROFILES['off'])];
    }

    public static function compile(Domain $domain): array
    {
        return [
            ...self::profile($domain->waf_profile),
            'ruleset' => config('security.waf.ruleset'),
            // Exclusions are inert until a per-rule, per-target removal exists.
            'exclusions' => [],
        ];
    }
}
