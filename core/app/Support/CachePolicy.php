<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class CachePolicy
{
    public const PROFILES = [
        'small' => ['disk_bytes' => 268435456, 'temporary_bytes' => 67108864, 'minimum_free_bytes' => 33554432, 'inactive_seconds' => 1800, 'maximum_object_bytes' => 10485760, 'admissions_per_second' => 20],
        'standard' => ['disk_bytes' => 1073741824, 'temporary_bytes' => 134217728, 'minimum_free_bytes' => 67108864, 'inactive_seconds' => 3600, 'maximum_object_bytes' => 104857600, 'admissions_per_second' => 50],
        'large' => ['disk_bytes' => 4294967296, 'temporary_bytes' => 536870912, 'minimum_free_bytes' => 268435456, 'inactive_seconds' => 21600, 'maximum_object_bytes' => 536870912, 'admissions_per_second' => 100],
        'streaming' => ['disk_bytes' => 8589934592, 'temporary_bytes' => 1073741824, 'minimum_free_bytes' => 536870912, 'inactive_seconds' => 86400, 'maximum_object_bytes' => 1073741824, 'admissions_per_second' => 25],
    ];

    public const DEFAULT_TTLS = ['200' => 3600, '203' => 3600, '204' => 300, '206' => 0, '301' => 3600, '302' => 300, '404' => 60];

    public static function defaults(): array
    {
        return [
            'enabled' => true, 'edge_ttl_seconds' => 3600, 'browser_ttl_seconds' => 300,
            'maximum_object_bytes' => 104857600, 'respect_origin_headers' => true,
            'query_policy' => 'include_all', 'query_parameters' => [], 'bypass_cookie_names' => [],
            'status_ttl_seconds' => self::DEFAULT_TTLS, 'admission_requests' => 2,
            'stale_if_error_seconds' => 60, 'stale_while_revalidate_seconds' => 30,
            'mode' => 'normal', 'maximum_variants_per_resource' => 32,
        ];
    }

    public static function normalize(?array $settings): array
    {
        $settings ??= [];
        if (array_key_exists('include_query_string', $settings) && ! array_key_exists('query_policy', $settings)) {
            $settings['query_policy'] = $settings['include_query_string'] ? 'include_all' : 'ignore_all';
        }

        return [...self::defaults(), ...$settings];
    }

    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'edge_ttl_seconds' => ['required', 'integer', 'between:0,31536000'],
            'browser_ttl_seconds' => ['required', 'integer', 'between:0,31536000'],
            'maximum_object_bytes' => ['required', 'integer', 'between:1048576,1073741824'],
            'respect_origin_headers' => ['required', 'boolean'],
            'query_policy' => ['required', Rule::in(['include_all', 'ignore_all', 'include_selected', 'ignore_selected'])],
            'query_parameters' => ['present', 'array', 'max:32'],
            'query_parameters.*' => ['required', 'regex:/^[A-Za-z0-9_.~-]{1,64}$/', 'distinct'],
            'bypass_cookie_names' => ['present', 'array', 'max:32'],
            'bypass_cookie_names.*' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/', 'distinct'],
            'status_ttl_seconds' => ['required', 'array', 'max:16'],
            'status_ttl_seconds.*' => ['required', 'integer', 'between:0,31536000'],
            'admission_requests' => ['required', 'integer', 'between:1,10'],
            'stale_if_error_seconds' => ['required', 'integer', 'between:0,86400'],
            'stale_while_revalidate_seconds' => ['required', 'integer', 'between:0,86400'],
            'mode' => ['required', Rule::in(['normal', 'cache_only', 'stale_only'])],
            'maximum_variants_per_resource' => ['required', 'integer', 'between:1,128'],
        ];
    }

    public static function profile(string $name): array
    {
        return self::PROFILES[$name] ?? self::PROFILES['standard'];
    }
}
