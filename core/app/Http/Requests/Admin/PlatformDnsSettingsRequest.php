<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlatformDnsSettingsRequest extends FormRequest
{
    private const HOSTNAME = 'regex:/^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\.?$/i';

    public function rules(): array
    {
        return [
            'platform_domain' => ['required', 'string', 'max:253', self::HOSTNAME],
            'proxy_hostname' => ['required', 'string', 'max:253', self::HOSTNAME],
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
}
