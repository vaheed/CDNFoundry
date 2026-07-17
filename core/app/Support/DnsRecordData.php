<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DnsRecordData
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA', 'SRV', 'PTR'];

    /** @return array<string,mixed> */
    public static function validate(array $input, string $zone): array
    {
        if (isset($input['type']) && is_string($input['type'])) {
            $input['type'] = strtoupper($input['type']);
        }
        $validator = Validator::make($input, [
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'name' => ['required', 'string', 'max:253'],
            'content' => ['required_unless:mode,geo_dns', 'string', 'max:4096'],
            'ttl' => ['required', 'integer', 'between:30,2147483647'],
            'priority' => ['nullable', 'integer', 'between:0,65535'],
            'weight' => ['nullable', 'integer', 'between:0,65535'],
            'port' => ['nullable', 'integer', 'between:0,65535'],
            'mode' => ['nullable', 'in:dns_only,geo_dns'],
            'geo' => ['required_if:mode,geo_dns', 'nullable', 'array'],
        ]);
        $validator->after(function ($validator) use ($input, $zone): void {
            try {
                $type = strtoupper((string) ($input['type'] ?? ''));
                $name = self::normalizeOwner((string) ($input['name'] ?? ''), $zone);
                if (($input['mode'] ?? 'dns_only') === 'geo_dns') {
                    if (! in_array($type, ['A', 'AAAA'], true)) {
                        $validator->errors()->add('mode', 'Geo-DNS is supported only for A and AAAA records.');
                    } elseif (is_array($input['geo'] ?? null)) {
                        GeoDnsConfig::validate($input['geo'], $type);
                    }
                } else {
                    self::normalizeContent($type, (string) ($input['content'] ?? ''), $zone);
                }
                if ($type === 'MX' && ! array_key_exists('priority', $input)) {
                    $validator->errors()->add('priority', 'MX records require a priority.');
                }
                if ($type === 'SRV' && (! array_key_exists('priority', $input) || ! array_key_exists('weight', $input) || ! array_key_exists('port', $input))) {
                    $validator->errors()->add('priority', 'SRV records require priority, weight, and port.');
                }
                if ($type === 'SRV' && preg_match('/^_[a-z0-9-]+\._(?:tcp|udp|sctp)\./', $name) !== 1) {
                    $validator->errors()->add('name', 'SRV owners must begin with _service._protocol.');
                }
                if ($type !== 'SRV' && str_contains($name, '_')) {
                    $validator->errors()->add('name', 'Underscores are allowed only in SRV record owners.');
                }
                if ($type === 'PTR' && ! str_ends_with($zone, '.in-addr.arpa') && ! str_ends_with($zone, '.ip6.arpa')) {
                    $validator->errors()->add('type', 'PTR records are allowed only in managed reverse zones.');
                }
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('content', $exception->getMessage());
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $type = strtoupper((string) $input['type']);
        $mode = ($input['mode'] ?? 'dns_only') === 'geo_dns' ? 'geo_dns' : 'dns_only';
        $geo = $mode === 'geo_dns' ? GeoDnsConfig::validate($input['geo'], $type) : null;
        $content = $mode === 'geo_dns' ? $geo['default'][0] : self::normalizeContent($type, (string) $input['content'], $zone);

        return [
            'type' => $type,
            'name' => self::normalizeOwner((string) $input['name'], $zone),
            'content' => $content,
            'content_hash' => hash('sha256', $mode === 'geo_dns' ? json_encode($geo, JSON_THROW_ON_ERROR) : $content),
            'ttl' => (int) $input['ttl'],
            'priority' => (int) ($input['priority'] ?? 0),
            'weight' => (int) ($input['weight'] ?? 0),
            'port' => (int) ($input['port'] ?? 0),
            'mode' => $mode,
            'geo_config' => $geo,
        ];
    }

    public static function normalizeOwner(string $value, string $zone): string
    {
        $value = trim($value);
        if ($value === '@') {
            return $zone;
        }
        $bare = rtrim(mb_strtolower($value), '.');
        $fqdn = ($bare === $zone || str_ends_with($bare, '.'.$zone)) ? $bare : $bare.'.'.$zone;
        $ascii = idn_to_ascii(mb_strtolower($fqdn), IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253 || ($ascii !== $zone && ! str_ends_with($ascii, '.'.$zone))) {
            throw new \InvalidArgumentException('The record owner must be inside the managed zone.');
        }
        foreach (explode('.', $ascii) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9_-]+(?<!-)$/', $label) !== 1) {
                throw new \InvalidArgumentException('The record owner is invalid.');
            }
        }

        return $ascii;
    }

    private static function normalizeContent(string $type, string $value, string $zone): string
    {
        $value = trim($value);

        return match ($type) {
            'A' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $value : throw new \InvalidArgumentException('Content must be a valid IPv4 address.'),
            'AAAA' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? strtolower($value) : throw new \InvalidArgumentException('Content must be a valid IPv6 address.'),
            'CNAME', 'NS', 'MX', 'PTR' => self::normalizeTarget($value, $zone),
            'TXT' => strlen($value) <= 4096 && $value !== '' ? $value : throw new \InvalidArgumentException('TXT content is invalid.'),
            'CAA' => self::normalizeCaa($value),
            'SRV' => self::normalizeTarget($value, $zone),
            default => throw new \InvalidArgumentException('The record type is unsupported.'),
        };
    }

    private static function normalizeTarget(string $value, string $zone): string
    {
        if ($value === '.') {
            return $value;
        }
        if ($value === '@') {
            return rtrim($zone, '.').'.';
        }
        $fqdn = str_ends_with($value, '.') || str_contains($value, '.')
            ? rtrim($value, '.')
            : $value.'.'.$zone;
        $ascii = idn_to_ascii(mb_strtolower($fqdn), IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253) {
            throw new \InvalidArgumentException('Content must be a valid DNS name.');
        }
        foreach (explode('.', $ascii) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label) !== 1) {
                throw new \InvalidArgumentException('Content must be a valid DNS name.');
            }
        }

        return $ascii.'.';
    }

    private static function normalizeCaa(string $value): string
    {
        if (preg_match('/^(\d{1,3})\s+(issue|issuewild|iodef)\s+(?:"([^"\r\n]+)"|([^\s"\r\n]+))$/', $value, $matches) !== 1
            || (int) $matches[1] > 255) {
            throw new \InvalidArgumentException('CAA content must contain flags (0-255), a supported tag, and a value.');
        }
        $content = $matches[3] !== '' ? $matches[3] : $matches[4];

        return ((int) $matches[1]).' '.strtolower($matches[2]).' "'.$content.'"';
    }
}
