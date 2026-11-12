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

    /** Cookie names that analytics and consent tools set without changing the response. A trailing * matches a prefix. */
    public const COOKIE_PRESETS = [
        'google_analytics' => ['label' => 'Google Analytics', 'names' => ['_ga', '_ga_*', '_gid', '_gat', '_gcl_au']],
        'meta_pixel' => ['label' => 'Meta Pixel', 'names' => ['_fbp', '_fbc']],
        'hotjar' => ['label' => 'Hotjar', 'names' => ['_hjSessionUser_*', '_hjSession_*', '_hjFirstSeen']],
        'matomo' => ['label' => 'Matomo', 'names' => ['_pk_id.*', '_pk_ses.*']],
        'consent' => ['label' => 'Consent banners', 'names' => ['cookieconsent_status', 'CookieConsent']],
    ];

    public const MAX_PATH_RULES = 16;

    public static function defaults(): array
    {
        return [
            'enabled' => true, 'edge_ttl_seconds' => 3600, 'browser_ttl_seconds' => 300,
            'maximum_object_bytes' => 104857600, 'respect_origin_headers' => true,
            'query_policy' => 'include_all', 'query_parameters' => [], 'ignore_cookie_names' => [], 'path_rules' => [],
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

        unset($settings['bypass_cookie_names']);

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
            'ignore_cookie_names' => ['present', 'array', 'max:32'],
            'ignore_cookie_names.*' => ['required', 'regex:/^[A-Za-z0-9_.-]{1,63}\*?$/', 'distinct'],
            'path_rules' => ['present', 'array', 'max:'.self::MAX_PATH_RULES],
            'path_rules.*' => ['required', 'array:match,value,action,ttl_seconds'],
            'path_rules.*.match' => ['required', Rule::in(['prefix', 'suffix'])],
            'path_rules.*.value' => ['required', 'string', 'regex:/^[A-Za-z0-9\/_.~%-]{1,128}$/'],
            'path_rules.*.action' => ['required', Rule::in(['bypass', 'ttl'])],
            'path_rules.*.ttl_seconds' => ['required_if:path_rules.*.action,ttl', 'prohibited_if:path_rules.*.action,bypass', 'nullable', 'integer', 'between:1,31536000'],
            'status_ttl_seconds' => ['required', 'array', 'max:16'],
            'status_ttl_seconds.*' => ['required', 'integer', 'between:0,31536000'],
            'admission_requests' => ['required', 'integer', 'between:1,10'],
            'stale_if_error_seconds' => ['required', 'integer', 'between:0,86400'],
            'stale_while_revalidate_seconds' => ['required', 'integer', 'between:0,86400'],
            'mode' => ['required', Rule::in(['normal', 'cache_only', 'stale_only'])],
            'maximum_variants_per_resource' => ['required', 'integer', 'between:1,128'],
        ];
    }

    /** Cross-field checks the per-field rules cannot express. Returns messages keyed by field. */
    public static function pathRuleErrors(array $rules): array
    {
        $errors = [];
        foreach (array_values($rules) as $index => $rule) {
            if (($rule['match'] ?? null) === 'prefix' && is_string($rule['value'] ?? null) && ! str_starts_with($rule['value'], '/')) {
                $errors["path_rules.$index.value"] = 'A path prefix must start with /.';
            }
        }

        return $errors;
    }

    public static function profile(string $name): array
    {
        return self::PROFILES[$name] ?? self::PROFILES['standard'];
    }
}
