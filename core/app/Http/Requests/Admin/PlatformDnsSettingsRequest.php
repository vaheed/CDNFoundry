<?php

namespace App\Http\Requests\Admin;

use App\Support\NetworkAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

class PlatformDnsSettingsRequest extends FormRequest
{
    private const HOSTNAME = 'regex:/^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\.?$/i';

    public static function ruleSet(): array
    {
        return [
            'platform_domain' => ['required', 'string', 'max:253', self::HOSTNAME],
            'proxy_hostname' => ['required', 'string', 'max:220', self::HOSTNAME],
            'nameservers' => ['required', 'array', 'min:2', 'max:8'],
            'nameservers.*.hostname' => ['required', 'distinct', 'string', 'max:253', self::HOSTNAME],
            'nameservers.*.ipv4' => ['required', 'ipv4'],
            'nameservers.*.ipv6' => ['required', 'ipv6'],
            'soa_primary' => ['required', 'string', 'max:253', self::HOSTNAME],
            'soa_mailbox' => ['required', 'string', 'max:253', self::HOSTNAME],
            'soa_refresh' => ['required', 'integer', 'between:300,86400'],
            'soa_retry' => ['required', 'integer', 'between:60,86400'],
            'soa_expire' => ['required', 'integer', 'between:86400,2419200'],
            'soa_minimum_ttl' => ['required', 'integer', 'between:30,86400'],
            'default_ttl' => ['required', 'integer', 'between:30,86400'],
            'cluster_targets' => ['required', 'array', 'min:1', 'max:16'],
            'cluster_targets.*' => ['required', 'string', 'max:253'],
        ];
    }

    public function rules(): array
    {
        return self::ruleSet();
    }

    public function withValidator(LaravelValidator $validator): void
    {
        self::addTypedChecks($validator);
    }

    /** @return array<string, mixed> */
    public static function validateInput(array $input): array
    {
        $input = self::normalize($input);
        $validator = Validator::make($input, self::ruleSet());
        self::addTypedChecks($validator);

        return $validator->validate();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::normalize($this->all()));
    }

    private static function addTypedChecks(LaravelValidator $validator): void
    {
        $validator->after(function (LaravelValidator $validator): void {
            $data = $validator->getData();
            $platform = rtrim((string) ($data['platform_domain'] ?? ''), '.');
            $insidePlatform = fn (?string $hostname): bool => is_string($hostname)
                && ($hostname === $platform || str_ends_with(rtrim($hostname, '.'), '.'.$platform));
            if (isset($data['proxy_hostname']) && (! $insidePlatform($data['proxy_hostname']) || rtrim($data['proxy_hostname'], '.') === $platform)) {
                $validator->errors()->add('proxy_hostname', 'The proxy hostname must be a subdomain of the platform domain.');
            }
            foreach ($data['nameservers'] ?? [] as $index => $nameserver) {
                if (! $insidePlatform($nameserver['hostname'] ?? null)) {
                    $validator->errors()->add("nameservers.$index.hostname", 'Nameserver hostnames must be inside the platform domain.');
                }
                foreach (['ipv4', 'ipv6'] as $family) {
                    $address = $nameserver[$family] ?? null;
                    if (is_string($address) && NetworkAddress::isUnsafe($address)) {
                        $validator->errors()->add("nameservers.$index.$family", 'Glue addresses must be public unicast service addresses.');
                    }
                }
            }
            if (isset($data['soa_primary']) && ! $insidePlatform($data['soa_primary'])) {
                $validator->errors()->add('soa_primary', 'The SOA primary must be inside the platform domain.');
            }
            if (isset($data['soa_expire'], $data['soa_refresh'], $data['soa_retry'])
                && (int) $data['soa_expire'] <= (int) $data['soa_refresh'] + (int) $data['soa_retry']) {
                $validator->errors()->add('soa_expire', 'The SOA expiry must be greater than refresh plus retry.');
            }
            $targets = [];
            foreach ($data['cluster_targets'] ?? [] as $index => $target) {
                $target = strtolower(trim((string) $target));
                if (isset($targets[$target])) {
                    $validator->errors()->add("cluster_targets.$index", 'DNS cluster targets must be distinct.');
                }
                $targets[$target] = true;
                if (preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\[[0-9a-f:]+\])(?::([1-9][0-9]{0,4}))?$/i', $target, $matches) !== 1
                    || (isset($matches[1]) && (int) $matches[1] > 65535)) {
                    $validator->errors()->add("cluster_targets.$index", 'DNS cluster targets must be bounded host or host:port values.');
                }
            }
        });
    }

    private static function normalize(array $input): array
    {
        foreach (['platform_domain', 'proxy_hostname', 'soa_primary', 'soa_mailbox'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = mb_strtolower(rtrim(trim($input[$field]), '.'));
            }
        }
        foreach ($input['nameservers'] ?? [] as $index => $nameserver) {
            if (isset($nameserver['hostname']) && is_string($nameserver['hostname'])) {
                $input['nameservers'][$index]['hostname'] = mb_strtolower(rtrim(trim($nameserver['hostname']), '.'));
            }
        }
        foreach ($input['cluster_targets'] ?? [] as $index => $target) {
            if (is_string($target)) {
                $input['cluster_targets'][$index] = strtolower(trim($target));
            }
        }

        return $input;
    }
}
