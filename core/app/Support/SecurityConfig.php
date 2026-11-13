<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SecurityConfig
{
    public const MANUAL_PROFILE = 'manual';

    /**
     * Reasons that show a domain's own protections working or traffic that never reached the domain. They stay visible as
     * security events but never push the domain toward a stricter automatic state.
     */
    public const NON_ESCALATING_REASONS = [
        'domain_restricted', 'domain_quarantined', 'edge_emergency_mode', 'invalid_method',
        'unknown_host', 'unknown_sni', 'sni_host_mismatch',
        // The incident profile working: it never pushes the domain toward a stricter automatic state.
        'incident_method', 'incident_body_size', 'challenge_required', 'challenge_invalid',
        // A domain's own custom rules working: they never push the domain toward a stricter automatic state.
        'custom_rule_block', 'custom_rule_challenge', 'custom_rule_log', 'custom_rule_rate_limited',
        // A log-only managed rule matched: the domain chose to watch it, not to count it.
        'waf_rule_logged',
        // Platform rulesets working: set by the operator for the domain, they never push it toward a stricter state.
        'platform_rule_block', 'platform_rule_challenge', 'platform_rule_log', 'platform_rule_rate_limited',
        // Detections working: they only log what the response held, so they never push the domain toward a stricter state.
        'sensitive_data_card', 'sensitive_data_iban', 'sensitive_data_private_key', 'sensitive_data_api_key',
    ];

    /** Automatic escalation looks at the rate over a short window, never at a lifetime counter. */
    public const RATE_WINDOW_MINUTES = 5;

    public const SUSPECT_EVENTS_PER_WINDOW = 50;

    public const RESTRICT_EVENTS_PER_WINDOW = 100;

    /** A domain moves back one step after this many minutes without an escalating event. */
    public const QUIET_MINUTES = 10;

    /** The reason codes an edge cell may report; the same file is checked against the Lua runtime in tests/contract. */
    public static function reasonCodes(): array
    {
        static $codes = null;

        return $codes ??= json_decode((string) file_get_contents(resource_path('contracts/security-event-reasons.json')), true, 8, JSON_THROW_ON_ERROR)['reasons'];
    }

    public static function defaults(string $profile = 'standard'): array
    {
        $profile = array_key_exists($profile, self::profileOptions()) ? $profile : 'standard';

        return [
            'profile' => $profile,
            'quarantine_policy' => 'manual',
            'allowed_methods' => config('security.allowed_methods'),
            'trusted_proxy_cidrs' => [],
            'limits' => self::ceilingLimits($profile),
        ];
    }

    public static function profileOptions(): array
    {
        return [
            'standard' => 'Normal traffic: recommended limits',
            'protected' => 'Elevated risk: tighter rate and connection limits',
            'quarantine' => 'Isolation: strictest rate and connection limits',
            self::MANUAL_PROFILE => 'Advanced: custom limits',
        ];
    }

    public static function profileDescription(string $profile): string
    {
        return match ($profile) {
            'protected' => 'Tighter rate and connection limits for traffic under elevated risk. This is not WAF attack inspection.',
            'quarantine' => 'The strictest limits for isolated high-risk traffic. This is not WAF attack inspection.',
            self::MANUAL_PROFILE => 'Advanced: edit one custom set of limits up to the platform safety ceilings.',
            default => 'Recommended rate and connection limits for normal traffic. This is separate from WAF.',
        };
    }

    public static function ceilingLimits(string $profile): array
    {
        if ($profile === self::MANUAL_PROFILE) {
            return collect(config('security.profiles'))
                ->reduce(function (array $ceilings, array $limits): array {
                    foreach ($limits as $field => $value) {
                        $ceilings[$field] = max($ceilings[$field] ?? $value, $value);
                    }

                    return $ceilings;
                }, []);
        }

        $limits = config("security.profiles.$profile");

        return is_array($limits) ? $limits : config('security.profiles.standard');
    }

    public static function validateSettings(array $input): array
    {
        $profiles = array_keys(self::profileOptions());
        $profile = (string) ($input['profile'] ?? 'standard');
        if (! in_array($profile, $profiles, true)) {
            throw ValidationException::withMessages(['profile' => 'The selected security profile is invalid.']);
        }
        $ceilings = self::ceilingLimits($profile);
        $integerRules = [];
        foreach ($ceilings as $field => $maximum) {
            $minimum = in_array($field, ['origin_retry_limit'], true) ? 0 : 1;
            $integerRules["limits.$field"] = ['required', 'integer', "between:$minimum,$maximum"];
            if ($profile !== self::MANUAL_PROFILE) {
                $integerRules["limits.$field"][] = Rule::in([$maximum]);
            }
        }
        $data = Validator::make($input, [
            'profile' => ['required', Rule::in($profiles)],
            'quarantine_policy' => ['required', Rule::in(['manual', 'automatic', 'automatic_with_admin_notification'])],
            'allowed_methods' => ['required', 'array', 'min:1', 'max:7'],
            'allowed_methods.*' => ['required', 'string', 'distinct', Rule::in(config('security.allowed_methods'))],
            'trusted_proxy_cidrs' => ['present', 'array', 'max:'.config('security.trusted_proxy_cidrs_maximum')],
            'trusted_proxy_cidrs.*' => ['required', 'string', 'max:64'],
            'limits' => ['required', 'array', 'size:'.count($ceilings)],
            ...$integerRules,
        ])->validate();
        $data['allowed_methods'] = array_values(array_unique(array_map('strtoupper', $data['allowed_methods'])));
        $data['trusted_proxy_cidrs'] = array_map(fn (string $cidr): string => self::validateCidr($cidr), $data['trusted_proxy_cidrs']);
        $data['limits'] = array_map('intval', $data['limits']);

        return $data;
    }

    public static function compile(Domain $domain): array
    {
        $configured = is_array($domain->security_settings) ? $domain->security_settings : self::defaults();
        $enforcedProfile = match ($domain->security_state) {
            'quarantined' => 'quarantine',
            'restricted', 'suspected' => 'protected',
            default => $configured['profile'],
        };
        $enforced = self::ceilingLimits($enforcedProfile);
        $limits = [];
        foreach ($enforced as $name => $maximum) {
            $limits[$name] = min((int) ($configured['limits'][$name] ?? $maximum), (int) $maximum);
        }

        return [
            'profile' => $configured['profile'], 'effective_profile' => $enforcedProfile,
            'state' => $domain->security_state, 'quarantine_policy' => $configured['quarantine_policy'],
            'allowed_methods' => array_values($configured['allowed_methods']),
            'trusted_proxy_cidrs' => array_values($configured['trusted_proxy_cidrs']),
            'configured_limits' => $configured['limits'],
            'limits' => $limits,
            'credential_check' => CredentialCheckData::forEdge($domain->credential_check),
            'sensitive_data' => SensitiveDataCheckData::forEdge($domain->sensitive_data_check),
            'ai_protection' => AiProtectionData::forEdge($domain->ai_protection),
            'platform_rules' => PlatformRulesets::forDomain($domain),
            'rules' => $domain->securityRules()->where('enabled', true)->orderBy('priority')->orderBy('id')
                ->get(['id', 'name', 'preset', 'conditions', 'rate_limit', 'action', 'priority'])->toArray(),
        ];
    }

    /**
     * The operational overlay a cell applies on top of the signed snapshot for one domain. It carries the automatic state
     * and, while the state tightens limits, the limits that apply. A cell takes the stricter of snapshot and overlay.
     */
    public static function overlay(Domain $domain): array
    {
        $state = (string) $domain->automatic_security_state;
        $limits = null;
        if (in_array($state, ['suspected', 'restricted'], true)) {
            $configured = is_array($domain->security_settings) ? $domain->security_settings : self::defaults();
            $limits = [];
            foreach (self::ceilingLimits('protected') as $name => $maximum) {
                $limits[$name] = min((int) ($configured['limits'][$name] ?? $maximum), (int) $maximum);
            }
        }

        return ['state' => $state, 'limits' => $limits, 'incident' => IncidentProfile::payload($domain)];
    }

    private static function validateCidr(string $value): string
    {
        if (! preg_match('/^(.+)\/(\d{1,3})$/', trim($value), $matches)) {
            throw ValidationException::withMessages(['value' => 'The value must be a valid IPv4 or IPv6 CIDR.']);
        }
        $packed = @inet_pton(trim($matches[1]));
        $address = $packed === false ? false : inet_ntop($packed);
        $maximum = str_contains($matches[1], ':') ? 128 : 32;
        $prefix = (int) $matches[2];
        if ($address === false || $prefix < 0 || $prefix > $maximum) {
            throw ValidationException::withMessages(['value' => 'The value must be a valid IPv4 or IPv6 CIDR.']);
        }

        return "$address/$prefix";
    }
}
