<?php

namespace App\Http\Requests\Admin;

use App\Models\NameserverAddressProbe;
use App\Models\PlatformDnsSetting;
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
            'nameservers.*.ipv6' => ['nullable', 'ipv6'],
            'customer_nameserver_pairs' => ['required', 'array', 'min:1', 'max:8'],
            'customer_nameserver_pairs.*' => ['required', 'array', 'size:2'],
            'customer_nameserver_pairs.*.*.hostname' => ['required', 'string', 'max:253', self::HOSTNAME],
            'customer_nameserver_pairs.*.*.ipv4' => ['required', 'ipv4'],
            'customer_nameserver_pairs.*.*.ipv6' => ['nullable', 'ipv6'],
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
            if (is_array($data['nameservers'] ?? null) && count($data['nameservers']) % 2 !== 0) {
                $validator->errors()->add('nameservers', 'Nameservers are offered in pairs; provide an even number (ns1 and ns2, then ns3 and ns4).');
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
            $hostnames = [];
            foreach ($data['customer_nameserver_pairs'] ?? [] as $pairIndex => $pair) {
                foreach (is_array($pair) ? $pair : [] as $index => $nameserver) {
                    $hostname = $nameserver['hostname'] ?? null;
                    if (! $insidePlatform($hostname)) {
                        $validator->errors()->add("customer_nameserver_pairs.$pairIndex.$index.hostname", 'Customer nameserver hostnames must be inside the platform domain.');
                    }
                    if (is_string($hostname)) {
                        if (isset($hostnames[$hostname])) {
                            $validator->errors()->add("customer_nameserver_pairs.$pairIndex.$index.hostname", 'A hostname can belong to only one pair.');
                        }
                        $hostnames[$hostname] = true;
                        if (str_contains($hostname, '*')) {
                            $validator->errors()->add("customer_nameserver_pairs.$pairIndex.$index.hostname", 'Wildcard nameserver names are not allowed.');
                        }
                    }
                    foreach (['ipv4', 'ipv6'] as $family) {
                        $address = $nameserver[$family] ?? null;
                        if (is_string($address) && NetworkAddress::isUnsafe($address)) {
                            $validator->errors()->add("customer_nameserver_pairs.$pairIndex.$index.$family", 'Glue addresses must be public unicast service addresses.');
                        }
                    }
                }
            }
            self::requireAnsweringIpv6($validator, $data, $platform);
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

    /**
     * Glue that nothing answers on makes resolvers wait for a timeout before trying another address, so a
     * new IPv6 nameserver address is saved only after an edge outside the control plane saw it answer.
     * Asking is a side effect of the check: save again once the probe has answered. Addresses already saved
     * are not asked again; they were accepted before.
     */
    private static function requireAnsweringIpv6(LaravelValidator $validator, array $data, string $platform): void
    {
        $current = PlatformDnsSetting::query()->find(1);
        $saved = collect([...($current?->nameservers ?? []), ...collect($current?->customer_nameserver_pairs ?? [])->flatten(1)->all()])
            ->pluck('ipv6')->filter()->map(fn (string $address): string => NameserverAddressProbe::canonical($address))->all();
        $addresses = [];
        foreach ($data['nameservers'] ?? [] as $index => $nameserver) {
            $addresses["nameservers.$index.ipv6"] = $nameserver['ipv6'] ?? null;
        }
        foreach ($data['customer_nameserver_pairs'] ?? [] as $pairIndex => $pair) {
            foreach (is_array($pair) ? $pair : [] as $index => $nameserver) {
                $addresses["customer_nameserver_pairs.$pairIndex.$index.ipv6"] = $nameserver['ipv6'] ?? null;
            }
        }
        foreach ($addresses as $field => $address) {
            if (! is_string($address) || $validator->errors()->has($field) || in_array(NameserverAddressProbe::canonical($address), $saved, true)
                || NameserverAddressProbe::isAnswering($address)) {
                continue;
            }
            $asked = $platform !== '' && NameserverAddressProbe::request($address, $platform);
            $validator->errors()->add($field, $asked
                ? 'This IPv6 address has not been seen answering DNS from outside the control plane. A probe was requested; save again once it answers.'
                : 'This IPv6 address cannot be verified because no registered edge is available to probe it.');
        }
    }

    private static function normalize(array $input): array
    {
        foreach (['soa_refresh', 'soa_retry', 'soa_expire', 'soa_minimum_ttl', 'default_ttl'] as $field) {
            $value = $input[$field] ?? null;
            if ((is_string($value) && preg_match('/^\d+$/', $value) === 1)
                || (is_float($value) && is_finite($value) && floor($value) === $value)) {
                $input[$field] = (int) $input[$field];
            }
        }
        foreach (['platform_domain', 'proxy_hostname', 'soa_primary', 'soa_mailbox'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = mb_strtolower(rtrim(trim($input[$field]), '.'));
            }
        }
        foreach ($input['nameservers'] ?? [] as $index => $nameserver) {
            if (isset($nameserver['hostname']) && is_string($nameserver['hostname'])) {
                $input['nameservers'][$index]['hostname'] = mb_strtolower(rtrim(trim($nameserver['hostname']), '.'));
            }
            $input['nameservers'][$index]['ipv6'] = filled($nameserver['ipv6'] ?? null) ? $nameserver['ipv6'] : null;
        }
        if (! isset($input['customer_nameserver_pairs']) && is_array($input['nameservers'] ?? null)) {
            // Omitted: offer the platform nameservers two at a time, as the stored default.
            $input['customer_nameserver_pairs'] = array_values(array_filter(array_chunk($input['nameservers'], 2), fn (array $pair): bool => count($pair) === 2));
        }
        foreach ($input['customer_nameserver_pairs'] ?? [] as $pairIndex => $pair) {
            foreach (is_array($pair) ? $pair : [] as $index => $nameserver) {
                if (isset($nameserver['hostname']) && is_string($nameserver['hostname'])) {
                    $input['customer_nameserver_pairs'][$pairIndex][$index]['hostname'] = mb_strtolower(rtrim(trim($nameserver['hostname']), '.'));
                }
                $input['customer_nameserver_pairs'][$pairIndex][$index]['ipv6'] = filled($nameserver['ipv6'] ?? null) ? $nameserver['ipv6'] : null;
            }
            $input['customer_nameserver_pairs'][$pairIndex] = array_values((array) $pair);
        }
        foreach ($input['cluster_targets'] ?? [] as $index => $target) {
            if (is_string($target)) {
                $input['cluster_targets'][$index] = strtolower(trim($target));
            }
        }

        return $input;
    }
}
