<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Presets over the typed request rules. A preset asks for a few values and writes ordinary rules marked with its name, so
 * order, limits, and revisions stay the ones every rule uses. Saving a preset again replaces its rules.
 */
final class RequestRulePresets
{
    public const HTTP_TO_HTTPS = 'http_to_https';

    public const WWW_TO_APEX = 'www_to_apex';

    public const APEX_TO_WWW = 'apex_to_www';

    public const MERGE_HOSTS = 'merge_hosts';

    public const MOVE_PATHS = 'move_paths';

    public const STRIP_PREFIX = 'strip_prefix';

    public const ADD_PREFIX = 'add_prefix';

    public const SECURITY_HEADERS = 'security_headers';

    public const HIDE_ORIGIN_HEADERS = 'hide_origin_headers';

    public const CORS_STATIC = 'cors_static';

    public const CACHE_STATIC = 'cache_static';

    public const BYPASS_PATHS = 'bypass_paths';

    public const BYPASS_COOKIES = 'bypass_cookies';

    public const PRESETS = [
        self::HTTP_TO_HTTPS => ['type' => 'redirect', 'label' => 'Redirect HTTP to HTTPS', 'remove' => 'Remove the HTTPS redirect'],
        self::WWW_TO_APEX => ['type' => 'redirect', 'label' => 'Redirect www to the apex domain', 'remove' => 'Remove the www redirect'],
        self::APEX_TO_WWW => ['type' => 'redirect', 'label' => 'Redirect the apex domain to www', 'remove' => 'Remove the apex redirect'],
        self::MERGE_HOSTS => ['type' => 'redirect', 'label' => 'Redirect other hostnames to one host', 'remove' => 'Remove the hostname redirects'],
        self::MOVE_PATHS => ['type' => 'redirect', 'label' => 'Move paths to new prefixes', 'remove' => 'Remove the path moves'],
        self::STRIP_PREFIX => ['type' => 'rewrite', 'label' => 'Remove a path prefix before the origin', 'remove' => 'Remove the prefix removal'],
        self::ADD_PREFIX => ['type' => 'rewrite', 'label' => 'Add a path prefix before the origin', 'remove' => 'Remove the added prefix'],
        self::SECURITY_HEADERS => ['type' => 'header', 'label' => 'Add security headers', 'remove' => 'Remove the security headers'],
        self::HIDE_ORIGIN_HEADERS => ['type' => 'header', 'label' => 'Hide origin software headers', 'remove' => 'Show origin software headers'],
        self::CORS_STATIC => ['type' => 'header', 'label' => 'Allow cross-origin use of static files', 'remove' => 'Remove the cross-origin header'],
        self::CACHE_STATIC => ['type' => 'cache', 'label' => 'Cache static files longer', 'remove' => 'Remove the static file rule'],
        self::BYPASS_PATHS => ['type' => 'cache', 'label' => 'Bypass the cache for admin and sign-in paths', 'remove' => 'Remove the path bypass'],
        self::BYPASS_COOKIES => ['type' => 'cache', 'label' => 'Bypass the cache for signed-in visitors', 'remove' => 'Remove the cookie bypass'],
    ];

    public const MAXIMUM_ITEMS = 20;

    public const STATIC_EXTENSIONS = ['css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf'];

    public const ORIGIN_SOFTWARE_HEADERS = ['server', 'x-powered-by', 'x-aspnet-version', 'x-aspnetmvc-version', 'x-generator'];

    public const FRAME_OPTIONS = ['DENY' => 'Never in a frame (DENY)', 'SAMEORIGIN' => 'Only in frames of this site (SAMEORIGIN)', 'none' => 'Do not send'];

    public const REFERRER_POLICIES = ['strict-origin-when-cross-origin', 'no-referrer', 'same-origin', 'strict-origin', 'no-referrer-when-downgrade'];

    /** Preset rules sit after the ones a person numbers by hand. */
    private const PRIORITY = 500;

    /** @return list<string> the presets of one rule type */
    public static function forType(string $type): array
    {
        return array_keys(array_filter(self::PRESETS, fn (array $preset): bool => $preset['type'] === $type));
    }

    /** @return list<array> the validated rules the preset writes for these values */
    public static function rules(Domain $domain, string $preset, array $input): array
    {
        $apex = strtolower($domain->name);

        return match ($preset) {
            self::HTTP_TO_HTTPS => [self::redirect('Redirect HTTP to HTTPS', [['field' => 'tls_version', 'operator' => 'equals', 'value' => 'none']],
                ['target' => 'https', 'status_code' => self::status($input), 'preserve_query' => true])],
            self::WWW_TO_APEX => [self::hostRedirect('Redirect www to '.$apex, ['www.'.$apex], $apex, self::status($input))],
            self::APEX_TO_WWW => [self::hostRedirect('Redirect '.$apex.' to www', [$apex], 'www.'.$apex, self::status($input))],
            self::MERGE_HOSTS => self::mergeHosts($input),
            self::MOVE_PATHS => self::moves($input),
            self::STRIP_PREFIX => [self::rewritePrefix('Remove '.self::prefixInput($input).' before the origin', self::prefixInput($input), '')],
            self::ADD_PREFIX => [self::rewritePrefix('Add '.self::prefixInput($input).' before the origin', '/', rtrim(self::prefixInput($input), '/').'/')],
            self::SECURITY_HEADERS => [self::securityHeaders($input)],
            self::HIDE_ORIGIN_HEADERS => [self::header('Hide origin software headers', [], ['request' => [],
                'response' => array_map(fn (string $name): array => ['op' => 'remove', 'name' => $name], self::ORIGIN_SOFTWARE_HEADERS)])],
            self::CORS_STATIC => [self::corsStatic($input)],
            self::CACHE_STATIC => [self::cacheStatic($input)],
            self::BYPASS_PATHS => self::bypass($input, 'paths', fn (string $path): array => ['field' => 'path', 'operator' => 'starts_with', 'value' => $path], 'Bypass the cache under '),
            self::BYPASS_COOKIES => self::bypass($input, 'cookies', fn (string $cookie): array => ['field' => 'cookie_present', 'operator' => 'equals', 'name' => $cookie, 'value' => true], 'Bypass the cache with cookie '),
            default => throw ValidationException::withMessages(['preset' => 'The preset must be one of: '.implode(', ', array_keys(self::PRESETS)).'.']),
        };
    }

    /** The preset's values as the user last saved them, or its defaults when it is not set. */
    public static function current(Domain $domain, string $preset): array
    {
        $rules = $domain->requestRules()->where('preset', $preset)->orderBy('priority')->orderBy('id')->get();
        $first = $rules->first();
        $defaults = match ($preset) {
            self::MERGE_HOSTS => ['hosts' => [], 'target' => strtolower($domain->name), 'status_code' => 301],
            self::MOVE_PATHS => ['moves' => [['from' => null, 'to' => null]], 'status_code' => 301],
            self::STRIP_PREFIX, self::ADD_PREFIX => ['prefix' => null],
            self::SECURITY_HEADERS => ['hsts_max_age' => 31536000, 'hsts_subdomains' => false, 'frame_options' => 'SAMEORIGIN', 'referrer_policy' => 'strict-origin-when-cross-origin'],
            self::CORS_STATIC => ['extensions' => ['woff', 'woff2', 'ttf', 'css', 'js'], 'origin' => '*'],
            self::CACHE_STATIC => ['extensions' => self::STATIC_EXTENSIONS, 'edge_ttl_days' => 30, 'browser_ttl_days' => 7],
            self::BYPASS_PATHS => ['paths' => ['/admin', '/login', '/wp-admin']],
            self::BYPASS_COOKIES => ['cookies' => ['session']],
            default => ['status_code' => 301],
        };
        if ($first === null) {
            return $defaults;
        }

        return match ($preset) {
            self::MERGE_HOSTS => ['hosts' => $first->conditions[0]['value'], 'target' => $first->action['host'], 'status_code' => $first->action['status_code']],
            self::MOVE_PATHS => ['moves' => $rules->map(fn ($rule): array => ['from' => $rule->action['from_prefix'], 'to' => $rule->action['to_prefix']])->all(), 'status_code' => $first->action['status_code']],
            self::STRIP_PREFIX => ['prefix' => $first->action['from_prefix']],
            self::ADD_PREFIX => ['prefix' => rtrim($first->action['to_prefix'], '/')],
            self::SECURITY_HEADERS => self::currentSecurityHeaders($first->action['response'], $defaults),
            self::CORS_STATIC => ['extensions' => $first->conditions[0]['value'], 'origin' => $first->action['response'][0]['value']],
            self::CACHE_STATIC => ['extensions' => $first->conditions[0]['value'], 'edge_ttl_days' => intdiv($first->action['edge_ttl_seconds'] ?? 0, 86400), 'browser_ttl_days' => intdiv($first->action['browser_ttl_seconds'] ?? 0, 86400)],
            self::BYPASS_PATHS => ['paths' => $rules->map(fn ($rule): string => $rule->conditions[0]['value'])->all()],
            self::BYPASS_COOKIES => ['cookies' => $rules->map(fn ($rule): string => $rule->conditions[0]['name'])->all()],
            self::HIDE_ORIGIN_HEADERS => [],
            default => ['status_code' => $first->action['status_code']],
        };
    }

    private static function status(array $input): int
    {
        return (int) Validator::make($input, ['status_code' => ['required', 'integer', Rule::in([301, 302, 307, 308])]])->validate()['status_code'];
    }

    private static function redirect(string $name, array $conditions, array $action, int $offset = 0): array
    {
        return RequestRuleData::validate(['type' => 'redirect', 'name' => $name, 'priority' => self::PRIORITY + $offset, 'conditions' => $conditions, 'action' => $action]);
    }

    private static function hostRedirect(string $name, array $from, string $to, int $status): array
    {
        return self::redirect($name, [count($from) === 1
            ? ['field' => 'hostname', 'operator' => 'equals', 'value' => $from[0]]
            : ['field' => 'hostname', 'operator' => 'in_list', 'value' => $from]],
            ['target' => 'host', 'host' => $to, 'scheme' => 'same', 'status_code' => $status, 'preserve_query' => true], 5);
    }

    private static function mergeHosts(array $input): array
    {
        $status = self::status($input);
        $data = Validator::make($input, [
            'hosts' => ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_ITEMS], 'hosts.*' => ['required', 'string', 'max:253'], 'target' => ['required', 'string', 'max:253'],
        ])->validate();
        $hosts = array_values(array_unique(array_map(fn (string $host): string => strtolower(trim($host)), $data['hosts'])));
        $target = strtolower(trim($data['target']));
        if (in_array($target, $hosts, true)) {
            throw ValidationException::withMessages(['hosts' => 'The host the others redirect to cannot be one of them.']);
        }

        return [self::rethrow('hosts', fn () => self::hostRedirect('Redirect other hostnames to '.$target, $hosts, $target, $status))];
    }

    private static function moves(array $input): array
    {
        $status = self::status($input);
        $data = Validator::make($input, [
            'moves' => ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_ITEMS],
            'moves.*.from' => ['required', 'string', 'max:'.RequestRuleData::MAXIMUM_PREFIX_BYTES],
            'moves.*.to' => ['required', 'string', 'max:'.RequestRuleData::MAXIMUM_PREFIX_BYTES],
        ])->validate();
        $rules = [];
        $seen = [];
        foreach (array_values($data['moves']) as $index => $move) {
            if (isset($seen[$move['from']])) {
                throw ValidationException::withMessages(["moves.$index.from" => 'Each old prefix can be moved once.']);
            }
            $seen[$move['from']] = true;
            $rules[] = self::rethrow("moves.$index.to", fn () => self::redirect(mb_substr("Move {$move['from']} to {$move['to']}", 0, 100), [],
                ['target' => 'prefix', 'from_prefix' => $move['from'], 'to_prefix' => $move['to'], 'status_code' => $status, 'preserve_query' => true], 10 + $index));
        }

        return $rules;
    }

    private static function prefixInput(array $input): string
    {
        $prefix = rtrim(trim((string) Validator::make($input, ['prefix' => ['required', 'string', 'max:'.RequestRuleData::MAXIMUM_PREFIX_BYTES]])->validate()['prefix']), '/');
        if ($prefix === '' || ! str_starts_with($prefix, '/')) {
            throw ValidationException::withMessages(['prefix' => 'The prefix must be a path such as /api.']);
        }

        return $prefix;
    }

    private static function rewritePrefix(string $name, string $from, string $to): array
    {
        return self::rethrow('prefix', fn () => RequestRuleData::validate(['type' => 'rewrite', 'name' => mb_substr($name, 0, 100), 'priority' => self::PRIORITY, 'conditions' => [],
            'action' => ['path' => 'prefix', 'from_prefix' => $from, 'to_prefix' => $to, 'query' => 'keep']]));
    }

    private static function header(string $name, array $conditions, array $action, int $offset = 0): array
    {
        return RequestRuleData::validate(['type' => 'header', 'name' => $name, 'priority' => self::PRIORITY + $offset, 'conditions' => $conditions, 'action' => $action]);
    }

    private static function securityHeaders(array $input): array
    {
        $data = Validator::make($input, [
            'hsts_max_age' => ['required', 'integer', 'between:0,63072000'], 'hsts_subdomains' => ['required', 'boolean'],
            'frame_options' => ['required', Rule::in(array_keys(self::FRAME_OPTIONS))], 'referrer_policy' => ['required', Rule::in(self::REFERRER_POLICIES)],
        ])->validate();
        $response = [];
        if ((int) $data['hsts_max_age'] > 0) {
            $response[] = ['op' => 'set', 'name' => 'strict-transport-security', 'value' => 'max-age='.(int) $data['hsts_max_age'].($data['hsts_subdomains'] ? '; includeSubDomains' : '')];
        }
        $response[] = ['op' => 'set', 'name' => 'x-content-type-options', 'value' => 'nosniff'];
        if ($data['frame_options'] !== 'none') {
            $response[] = ['op' => 'set', 'name' => 'x-frame-options', 'value' => $data['frame_options']];
        }
        $response[] = ['op' => 'set', 'name' => 'referrer-policy', 'value' => $data['referrer_policy']];
        $response[] = ['op' => 'set', 'name' => 'permissions-policy', 'value' => 'camera=(), microphone=(), geolocation=()'];

        return self::header('Security headers', [], ['request' => [], 'response' => $response]);
    }

    private static function currentSecurityHeaders(array $response, array $defaults): array
    {
        $values = collect($response)->pluck('value', 'name');
        $hsts = (string) ($values['strict-transport-security'] ?? '');

        return [
            'hsts_max_age' => preg_match('/max-age=(\d+)/', $hsts, $match) ? (int) $match[1] : 0,
            'hsts_subdomains' => str_contains($hsts, 'includeSubDomains'),
            'frame_options' => $values['x-frame-options'] ?? 'none',
            'referrer_policy' => $values['referrer-policy'] ?? $defaults['referrer_policy'],
        ];
    }

    private static function extensions(array $input): array
    {
        $data = Validator::make($input, ['extensions' => ['required', 'array', 'min:1', 'max:50'], 'extensions.*' => ['required', 'string', 'max:10']])->validate();

        return array_values(array_unique(array_map(fn (string $extension): string => strtolower(ltrim(trim($extension), '.')), $data['extensions'])));
    }

    private static function corsStatic(array $input): array
    {
        $extensions = self::extensions($input);
        $origin = trim((string) Validator::make($input, ['origin' => ['required', 'string', 'max:253']])->validate()['origin']);
        if ($origin !== '*' && ! preg_match('/^https?:\/\/[a-z0-9.-]+(:\d{1,5})?$/i', $origin)) {
            throw ValidationException::withMessages(['origin' => 'The allowed origin must be * or an address such as https://app.example.com.']);
        }

        return self::rethrow('extensions', fn () => self::header('Allow cross-origin use of static files', [['field' => 'file_extension', 'operator' => 'in_list', 'value' => $extensions]],
            ['request' => [], 'response' => [['op' => 'set', 'name' => 'access-control-allow-origin', 'value' => $origin]]], 10));
    }

    private static function cacheStatic(array $input): array
    {
        $extensions = self::extensions($input);
        $data = Validator::make($input, ['edge_ttl_days' => ['required', 'integer', 'between:1,365'], 'browser_ttl_days' => ['required', 'integer', 'between:0,365']])->validate();

        return self::rethrow('extensions', fn () => RequestRuleData::validate(['type' => 'cache', 'name' => 'Cache static files longer', 'priority' => self::PRIORITY + 100,
            'conditions' => [['field' => 'file_extension', 'operator' => 'in_list', 'value' => $extensions]],
            'action' => ['mode' => 'override', 'edge_ttl_seconds' => (int) $data['edge_ttl_days'] * 86400, 'browser_ttl_seconds' => (int) $data['browser_ttl_days'] * 86400]]));
    }

    /** One bypass rule per path prefix or cookie name, before the other preset cache rules so a bypass wins. */
    private static function bypass(array $input, string $key, callable $condition, string $name): array
    {
        $data = Validator::make($input, [$key => ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_ITEMS], "$key.*" => ['required', 'string', 'max:128']])->validate();
        $rules = [];
        foreach (array_values(array_unique(array_map('trim', $data[$key]))) as $index => $value) {
            if ($key === 'paths' && ! str_starts_with($value, '/')) {
                throw ValidationException::withMessages([$key => "$value does not start with /. A path prefix looks like /admin."]);
            }
            $rules[] = self::rethrow($key, fn () => RequestRuleData::validate(['type' => 'cache', 'name' => mb_substr($name.$value, 0, 100), 'priority' => self::PRIORITY + $index,
                'conditions' => [$condition($value)], 'action' => ['mode' => 'bypass']]));
        }

        return $rules;
    }

    /** Reports a rule's validation error on the preset field the user typed. */
    private static function rethrow(string $key, callable $build): array
    {
        try {
            return $build();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$key => collect($exception->errors())->flatten()->first()]);
        }
    }
}
