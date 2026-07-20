<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SecurityConfig
{
    public const MANUAL_PROFILE = 'manual';

    public const REASON_CODES = [
        'unknown_host', 'unknown_sni', 'invalid_method', 'malformed_request', 'header_too_large',
        'body_too_large', 'header_timeout', 'body_timeout', 'client_rate_exceeded',
        'domain_rate_exceeded', 'client_connections_exceeded', 'domain_connections_exceeded',
        'tls_handshake_rate_exceeded', 'origin_capacity_exceeded', 'origin_circuit_open',
        'cache_abuse_detected', 'domain_restricted', 'domain_quarantined', 'edge_emergency_mode',
    ];

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
            'standard' => 'Standard — balanced recommended defaults',
            'protected' => 'Protected — stricter recommended defaults',
            'quarantine' => 'Quarantine — strictest recommended defaults',
            self::MANUAL_PROFILE => 'Manual — custom limits',
        ];
    }

    public static function profileDescription(string $profile): string
    {
        return match ($profile) {
            'protected' => 'Recommended for traffic under elevated risk. Its displayed limits are fixed.',
            'quarantine' => 'Recommended for isolating high-risk traffic. Its displayed limits are fixed.',
            self::MANUAL_PROFILE => 'Edit one custom set of limits. Values cannot exceed the platform safety ceilings.',
            default => 'Recommended for normal traffic. Its displayed limits are fixed.',
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
            'rules' => $domain->securityRules()->where('enabled', true)->orderBy('priority')->orderBy('id')
                ->get(['id', 'match_type', 'value', 'action', 'priority'])->toArray(),
        ];
    }

    public static function validateRule(array $input): array
    {
        $data = Validator::make($input, [
            'match_type' => ['required', Rule::in(['ip', 'cidr', 'country', 'continent'])],
            'value' => ['required', 'string', 'max:128'],
            'action' => ['required', Rule::in(['allow', 'block'])],
            'priority' => ['required', 'integer', 'between:-1000000,1000000'],
            'enabled' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:250'],
        ])->validate();
        $data['enabled'] = (bool) ($data['enabled'] ?? true);
        $data['value'] = match ($data['match_type']) {
            'ip' => self::validateIp($data['value']),
            'cidr' => self::validateCidr($data['value']),
            'country' => self::validateGeo($data['value'], GeoVocabulary::countries(), 'country'),
            'continent' => self::validateGeo($data['value'], GeoVocabulary::CONTINENTS, 'continent'),
        };

        return $data;
    }

    private static function validateIp(string $value): string
    {
        $packed = @inet_pton(trim($value));
        if ($packed === false) {
            throw ValidationException::withMessages(['value' => 'The value must be a valid IPv4 or IPv6 address.']);
        }

        return inet_ntop($packed);
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

    private static function validateGeo(string $value, array $allowed, string $label): string
    {
        $value = strtoupper(trim($value));
        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages(['value' => "The value must be a supported $label code."]);
        }

        return $value;
    }
}
