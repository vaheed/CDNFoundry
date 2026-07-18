<?php

namespace App\Support;

use App\Models\Edge;
use App\Models\EdgeCell;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class OriginData
{
    public static function validate(array $input): array
    {
        $validator = Validator::make($input, [
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'scheme' => ['required', 'in:http,https'],
            'host_header' => ['required', 'string', 'max:253'],
            'sni' => ['nullable', 'string', 'max:253'],
            'verify_tls' => ['required', 'boolean'],
            'connect_timeout_ms' => ['required', 'integer', 'between:100,10000'],
            'response_timeout_ms' => ['required', 'integer', 'between:500,60000'],
            'retry_count' => ['required', 'integer', 'between:0,2'],
            'websocket' => ['sometimes', 'boolean'],
            'health_check' => ['sometimes', 'nullable', 'array'],
            'health_check.enabled' => ['required_with:health_check', 'boolean'],
            'health_check.path' => ['required_if:health_check.enabled,true', 'nullable', 'string', 'starts_with:/', 'max:1024'],
            'health_check.interval_seconds' => ['required_if:health_check.enabled,true', 'nullable', 'integer', 'between:60,86400'],
        ]);
        $validator->after(function ($validator) use ($input): void {
            $host = trim((string) ($input['host'] ?? ''), '[]');
            if (! self::validHost($host)) {
                $validator->errors()->add('host', 'The origin host must be a valid IP address or DNS hostname.');
            }
            foreach (['host_header', 'sni'] as $field) {
                if (($input[$field] ?? null) !== null && ! self::validDnsName((string) $input[$field])) {
                    $validator->errors()->add($field, "The $field must be a valid DNS hostname.");
                }
            }
            if (($input['scheme'] ?? null) === 'https' && ($input['verify_tls'] ?? false) && empty($input['sni'])) {
                $validator->errors()->add('sni', 'Verified HTTPS origins require an explicit TLS SNI hostname.');
            }
            if (filter_var($host, FILTER_VALIDATE_IP) && self::blockedAddress($host)) {
                $validator->errors()->add('host', 'The origin address is blocked by the destination-safety policy.');
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return [
            'host' => strtolower(trim($input['host'], '[]')),
            'port' => (int) $input['port'], 'scheme' => $input['scheme'],
            'host_header' => strtolower(rtrim($input['host_header'], '.')),
            'sni' => isset($input['sni']) ? strtolower(rtrim($input['sni'], '.')) : null,
            'verify_tls' => (bool) $input['verify_tls'],
            'connect_timeout_ms' => (int) $input['connect_timeout_ms'],
            'response_timeout_ms' => (int) $input['response_timeout_ms'],
            'retry_count' => (int) $input['retry_count'], 'websocket' => (bool) ($input['websocket'] ?? false),
            'health_check' => (bool) ($input['health_check']['enabled'] ?? false) ? [
                'enabled' => (bool) $input['health_check']['enabled'], 'path' => $input['health_check']['path'],
                'interval_seconds' => (int) $input['health_check']['interval_seconds'],
            ] : null,
        ];
    }

    /** @return list<string> */
    public static function resolveAndValidate(string $host): array
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($addresses === []) {
            throw ValidationException::withMessages(['host' => 'The origin hostname did not resolve.']);
        }
        foreach ($addresses as $address) {
            if (self::blockedAddress($address)) {
                throw ValidationException::withMessages(['host' => 'The origin resolves to an address blocked by the destination-safety policy.']);
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function resolve(string $host): array
    {
        if (! function_exists('pcntl_alarm')) {
            throw ValidationException::withMessages(['host' => 'Bounded origin DNS resolution is unavailable.']);
        }
        $previousAsync = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, fn () => throw ValidationException::withMessages(['host' => 'Origin DNS resolution timed out.']));
        pcntl_alarm(5);
        try {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }

        return array_values(array_filter(array_map(fn (array $row) => $row['ip'] ?? $row['ipv6'] ?? null, $records)));
    }

    private static function blockedAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (Edge::query()->where('ipv4', $address)->orWhere('ipv6', $address)->exists()
            || EdgeCell::query()->where('service_ipv4', $address)->orWhere('service_ipv6', $address)->exists()
            || in_array($address, app(PlatformSettings::class)->get('origin_safety', 'blocked_origin_addresses'), true)) {
            return true;
        }
        if (NetworkAddress::isUnsafe($address)) {
            if (NetworkAddress::isPrivate($address)) {
                foreach (app(PlatformSettings::class)->get('origin_safety', 'private_origin_allowlist') as $cidr) {
                    if (NetworkAddress::inCidr($address, $cidr)) {
                        return false;
                    }
                }
            }

            return true;
        }
        foreach (app(PlatformSettings::class)->get('origin_safety', 'blocked_origin_networks') as $cidr) {
            if (NetworkAddress::inCidr($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function validHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false || self::validDnsName($host);
    }

    private static function validDnsName(string $host): bool
    {
        $ascii = idn_to_ascii(strtolower(rtrim($host, '.')), IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii !== false && strlen($ascii) <= 253 && collect(explode('.', $ascii))->every(fn ($label) => preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) === 1);
    }
}
