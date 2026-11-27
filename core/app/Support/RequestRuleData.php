<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\RequestRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The typed shape of a request rule: up to five conditions that must all match, from the firewall-rule condition builder,
 * and one action of the rule's type. Values are fixed text: there is no regular expression and no expression language.
 */
final class RequestRuleData
{
    public const TYPES = ['redirect', 'rewrite', 'header', 'cache'];

    public const TYPE_LABELS = ['redirect' => 'Redirects', 'rewrite' => 'URL rewrites', 'header' => 'Headers', 'cache' => 'Cache'];

    /** The singular noun of each type, for buttons and headings. */
    public const TYPE_NOUNS = ['redirect' => 'redirect', 'rewrite' => 'rewrite', 'header' => 'header rule', 'cache' => 'cache rule'];

    /** The snapshot key of each type's ordered list. */
    public const SNAPSHOT_KEYS = ['redirect' => 'redirects', 'rewrite' => 'rewrites', 'header' => 'headers', 'cache' => 'caches'];

    /** Only fields known before any detection runs, so a rule never makes a request pay for scoring. */
    public const FIELDS = [
        'hostname', 'path', 'query_present', 'method', 'country', 'continent', 'asn', 'client_ip', 'user_agent',
        'referer', 'referer_host', 'referer_present', 'file_extension', 'header', 'cookie_present', 'tls_version',
    ];

    public const REDIRECT_TARGETS = [
        'url' => 'A fixed URL', 'host' => 'The same path on another host', 'prefix' => 'Replace a path prefix', 'https' => 'The same address over HTTPS',
    ];

    public const REDIRECT_STATUSES = [301 => '301 Moved permanently', 302 => '302 Found', 307 => '307 Temporary redirect', 308 => '308 Permanent redirect'];

    public const REWRITE_PATHS = ['keep' => 'Keep the path', 'set' => 'Set a fixed path', 'prefix' => 'Replace a path prefix'];

    public const REWRITE_QUERIES = ['keep' => 'Keep the query string', 'drop' => 'Remove the query string', 'set' => 'Set a fixed query string'];

    public const HEADER_OPERATIONS = ['set' => 'Set', 'remove' => 'Remove'];

    public const CACHE_MODES = ['bypass' => 'Bypass the cache', 'override' => 'Cache with these settings'];

    public const MAXIMUM_TARGET_BYTES = 2048;

    public const MAXIMUM_PREFIX_BYTES = 512;

    public const MAXIMUM_QUERY_BYTES = 1024;

    public const MAXIMUM_HEADER_OPERATIONS = 10;

    public const MAXIMUM_HEADER_VALUE_BYTES = 1024;

    public const MAXIMUM_TTL_SECONDS = 31536000;

    /**
     * Headers a rule can never set or remove: framing and hop-by-hop headers, the forwarding headers the edge writes itself,
     * the compression the edge negotiates, and every platform header that carries a decision.
     */
    public const PROTECTED_HEADERS = [
        'host', 'content-length', 'transfer-encoding', 'connection', 'keep-alive', 'proxy-connection', 'te', 'trailer', 'upgrade',
        'proxy-authenticate', 'proxy-authorization', 'forwarded', 'x-real-ip', 'accept-encoding', 'content-encoding', 'content-range',
    ];

    public const PROTECTED_HEADER_PREFIXES = ['x-forwarded-', 'x-cdn-', 'x-cdnf-'];

    private const PATH = '/^\/[A-Za-z0-9\/_.~%!$&\'()*+,;=:@-]*$/';

    private const QUERY = '/^[A-Za-z0-9\/_.~%!$&\'()*+,;=:@?-]+$/';

    private const HEADER_NAME = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}$/';

    private const HOST = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    public static function maximumRulesPerType(): int
    {
        return (int) config('request_rules.maximum_rules_per_type', 50);
    }

    /** @return array<string, string> the condition fields a request rule offers, with their labels */
    public static function fieldLabels(): array
    {
        return array_intersect_key(SecurityRuleData::FIELD_LABELS, array_flip(self::FIELDS));
    }

    /**
     * Validates one rule and returns it in the stored shape: type, name, conditions, action, priority, enabled flag, and the
     * fingerprint that keeps one domain from holding the same rule twice.
     */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'type' => ['required', Rule::in(self::TYPES)],
            'name' => ['nullable', 'string', 'max:100'],
            'conditions' => ['present', 'array', 'max:'.SecurityRuleData::MAXIMUM_CONDITIONS],
            'conditions.*' => ['required', 'array'],
            'action' => ['required', 'array'],
            'priority' => ['required', 'integer', 'between:-1000000,1000000'],
            'enabled' => ['sometimes', 'boolean'],
        ])->validate();

        $conditions = [];
        foreach (array_values($data['conditions']) as $index => $condition) {
            if (! in_array($condition['field'] ?? null, self::FIELDS, true)) {
                throw ValidationException::withMessages(["conditions.$index.field" => 'The field must be one of: '.implode(', ', self::FIELDS).'.']);
            }
            $conditions[] = SecurityRuleData::condition($condition, "conditions.$index");
        }
        $action = match ($data['type']) {
            'redirect' => self::redirect($data['action'], $conditions),
            'rewrite' => self::rewrite($data['action']),
            'header' => self::header($data['action']),
            'cache' => self::cache($data['action']),
            default => throw ValidationException::withMessages(['type' => 'Choose a supported rule type.']),
        };
        // A prefix matches on its own prefix and a header rule may apply to every request; every other rule needs a condition.
        $matchesOnItsOwn = ($data['type'] === 'redirect' && $action['target'] === 'prefix') || ($data['type'] === 'rewrite' && $action['path'] === 'prefix')
            || $data['type'] === 'header';
        if ($conditions === [] && ! $matchesOnItsOwn) {
            throw ValidationException::withMessages(['conditions' => 'Add at least one condition.']);
        }

        return [
            'type' => $data['type'],
            'name' => isset($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : null,
            'conditions' => $conditions,
            'action' => $action,
            'priority' => (int) $data['priority'],
            'enabled' => (bool) ($data['enabled'] ?? true),
            'fingerprint' => self::fingerprint($data['type'], $conditions, $action),
        ];
    }

    public static function fingerprint(string $type, array $conditions, array $action): string
    {
        return hash('sha256', json_encode([$type, $conditions, $action], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** The row to insert for a validated rule: the typed structures are stored as JSON text. */
    public static function row(array $rule): array
    {
        return [...$rule, 'conditions' => json_encode($rule['conditions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'action' => json_encode($rule['action'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
    }

    /** The enabled rules of each type in evaluation order, for the signed snapshot. A type without rules is an empty list. */
    public static function forEdge(Domain $domain): array
    {
        $rules = $domain->requestRules()->where('enabled', true)->orderBy('priority')->orderBy('id')
            ->get(['id', 'type', 'conditions', 'action']);

        return collect(self::SNAPSHOT_KEYS)->mapWithKeys(fn (string $key, string $type): array => [$key => $rules->where('type', $type)
            ->map(fn (RequestRule $rule): array => ['id' => $rule->id, 'conditions' => $rule->conditions, 'action' => $rule->action])->values()->all()])->all();
    }

    /** One readable line for an action: "308 to https://example.com/new, keeping the query string". */
    public static function describeAction(string $type, array $action): string
    {
        return match ($type) {
            'redirect' => self::describeRedirect($action),
            'rewrite' => self::describeRewrite($action),
            'header' => self::describeHeaders($action),
            'cache' => self::describeCache($action),
            default => '',
        };
    }

    /** One readable line for the whole rule's match. */
    public static function describeConditions(string $type, array $conditions, array $action): string
    {
        $prefix = match (true) {
            $type === 'redirect' && ($action['target'] ?? null) === 'prefix' => 'Path starts with '.$action['from_prefix'],
            $type === 'rewrite' && ($action['path'] ?? null) === 'prefix' => 'Path starts with '.$action['from_prefix'],
            default => null,
        };
        $described = $conditions === [] ? null : SecurityRuleData::describe($conditions);

        return collect([$prefix, $described])->filter()->implode(' and ') ?: 'All requests';
    }

    private static function describeRedirect(array $action): string
    {
        $query = ($action['preserve_query'] ?? false) ? ', keeping the query string' : ', without the query string';

        return ((int) ($action['status_code'] ?? 0)).' '.match ($action['target'] ?? null) {
            'url' => 'to '.$action['url'],
            'host' => 'to the same path on '.(($action['scheme'] ?? 'same') === 'https' ? 'https://' : '').$action['host'],
            'prefix' => 'from '.$action['from_prefix'].' to '.$action['to_prefix'],
            'https' => 'to the same address over HTTPS',
            default => '',
        }.$query;
    }

    private static function describeRewrite(array $action): string
    {
        $path = match ($action['path'] ?? 'keep') {
            'set' => 'Path to '.$action['path_value'],
            'prefix' => 'Prefix '.$action['from_prefix'].' to '.($action['to_prefix'] === '' ? 'nothing' : $action['to_prefix']),
            default => null,
        };
        $query = match ($action['query'] ?? 'keep') {
            'drop' => 'remove the query string',
            'set' => 'query string to '.$action['query_value'],
            default => null,
        };

        return ucfirst(collect([$path, $query])->filter()->implode(', '));
    }

    private static function describeHeaders(array $action): string
    {
        $describe = fn (array $operation): string => $operation['op'] === 'remove' ? "remove {$operation['name']}" : "set {$operation['name']}";
        $parts = [];
        if (($action['request'] ?? []) !== []) {
            $parts[] = 'Request: '.implode(', ', array_map($describe, $action['request']));
        }
        if (($action['response'] ?? []) !== []) {
            $parts[] = 'Response: '.implode(', ', array_map($describe, $action['response']));
        }

        return implode('. ', $parts);
    }

    private static function describeCache(array $action): string
    {
        if (($action['mode'] ?? null) === 'bypass') {
            return 'Bypass the cache';
        }

        return collect([
            isset($action['edge_ttl_seconds']) ? 'Edge TTL '.self::duration($action['edge_ttl_seconds']) : null,
            isset($action['browser_ttl_seconds']) ? 'browser TTL '.self::duration($action['browser_ttl_seconds']) : null,
            isset($action['respect_origin']) ? ($action['respect_origin'] ? 'respect origin cache headers' : 'ignore origin cache headers') : null,
        ])->filter()->implode(', ');
    }

    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds === 0 => '0 s',
            $seconds % 86400 === 0 => ($seconds / 86400).' d',
            $seconds % 3600 === 0 => ($seconds / 3600).' h',
            $seconds % 60 === 0 => ($seconds / 60).' min',
            default => $seconds.' s',
        };
    }

    private static function redirect(array $input, array $conditions): array
    {
        $data = self::prefixed('action', fn () => Validator::make($input, [
            'target' => ['required', Rule::in(array_keys(self::REDIRECT_TARGETS))],
            'status_code' => ['required', 'integer', Rule::in(array_keys(self::REDIRECT_STATUSES))],
            'preserve_query' => ['required', 'boolean'],
            'url' => ['required_if:target,url', 'nullable', 'string', 'max:'.self::MAXIMUM_TARGET_BYTES],
            'host' => ['required_if:target,host', 'nullable', 'string', 'max:253'],
            'scheme' => ['nullable', Rule::in(['same', 'https'])],
            'from_prefix' => ['required_if:target,prefix', 'nullable', 'string', 'max:'.self::MAXIMUM_PREFIX_BYTES],
            'to_prefix' => ['required_if:target,prefix', 'nullable', 'string', 'max:'.self::MAXIMUM_PREFIX_BYTES],
        ], [], ['status_code' => 'status'])->validate());
        $action = ['target' => $data['target'], 'status_code' => (int) $data['status_code'], 'preserve_query' => (bool) $data['preserve_query']];
        $paths = self::equalPaths($conditions);
        $hosts = self::equalHosts($conditions);

        if ($data['target'] === 'https') {
            return $action;
        }
        if ($data['target'] === 'url') {
            $url = self::url((string) $data['url']);
            $target = parse_url($url);
            $targetPath = $target['path'] ?? '/';
            $sameHost = ! isset($target['host']) || $hosts === [] || in_array(strtolower($target['host']), $hosts, true);
            if ($sameHost && in_array($targetPath, $paths, true) && ! isset($target['query'])) {
                throw ValidationException::withMessages(['action.url' => 'The rule would redirect a request to the address it matches.']);
            }

            return [...$action, 'url' => $url];
        }
        if ($data['target'] === 'host') {
            $host = strtolower(trim((string) $data['host']));
            if (! preg_match(self::HOST, $host)) {
                throw ValidationException::withMessages(['action.host' => 'The host must be a host name such as www.example.com.']);
            }
            if (in_array($host, $hosts, true)) {
                throw ValidationException::withMessages(['action.host' => 'The rule would redirect a request to the host it matches.']);
            }

            return [...$action, 'host' => $host, 'scheme' => $data['scheme'] ?? 'same'];
        }

        $from = self::path((string) $data['from_prefix'], 'action.from_prefix');
        $to = self::path((string) $data['to_prefix'], 'action.to_prefix');
        if (str_starts_with($to, $from)) {
            throw ValidationException::withMessages(['action.to_prefix' => 'The new prefix cannot start with the old one: the redirected address would match again.']);
        }

        return [...$action, 'from_prefix' => $from, 'to_prefix' => $to];
    }

    private static function rewrite(array $input): array
    {
        $data = self::prefixed('action', fn () => Validator::make($input, [
            'path' => ['required', Rule::in(array_keys(self::REWRITE_PATHS))],
            'path_value' => ['required_if:path,set', 'nullable', 'string', 'max:'.self::MAXIMUM_TARGET_BYTES],
            'from_prefix' => ['required_if:path,prefix', 'nullable', 'string', 'max:'.self::MAXIMUM_PREFIX_BYTES],
            'to_prefix' => ['nullable', 'string', 'max:'.self::MAXIMUM_PREFIX_BYTES],
            'query' => ['required', Rule::in(array_keys(self::REWRITE_QUERIES))],
            'query_value' => ['required_if:query,set', 'nullable', 'string', 'max:'.self::MAXIMUM_QUERY_BYTES],
        ])->validate());
        if ($data['path'] === 'keep' && $data['query'] === 'keep') {
            throw ValidationException::withMessages(['action.path' => 'A rewrite must change the path or the query string.']);
        }
        $action = ['path' => $data['path'], 'query' => $data['query']];
        if ($data['path'] === 'set') {
            $action['path_value'] = self::rootedPath((string) $data['path_value'], 'action.path_value');
        }
        if ($data['path'] === 'prefix') {
            $action['from_prefix'] = self::rootedPath((string) $data['from_prefix'], 'action.from_prefix');
            $to = trim((string) ($data['to_prefix'] ?? ''));
            // An empty new prefix removes the old one: /api/users becomes /users.
            $action['to_prefix'] = $to === '' ? '' : self::rootedPath($to, 'action.to_prefix');
            if ($action['to_prefix'] === $action['from_prefix']) {
                throw ValidationException::withMessages(['action.to_prefix' => 'The new prefix is the same as the old one.']);
            }
        }
        if ($data['query'] === 'set') {
            $query = ltrim(trim((string) $data['query_value']), '?');
            if (! preg_match(self::QUERY, $query)) {
                throw ValidationException::withMessages(['action.query_value' => 'The query string must be printable URL characters such as lang=en&page=1, with no spaces.']);
            }
            $action['query_value'] = $query;
        }

        return $action;
    }

    private static function header(array $input): array
    {
        $data = self::prefixed('action', fn () => Validator::make($input, [
            'request' => ['present', 'array', 'max:'.self::MAXIMUM_HEADER_OPERATIONS],
            'response' => ['present', 'array', 'max:'.self::MAXIMUM_HEADER_OPERATIONS],
            'request.*' => ['required', 'array'],
            'response.*' => ['required', 'array'],
        ])->validate());
        $action = [];
        foreach (['request', 'response'] as $direction) {
            $action[$direction] = [];
            $seen = [];
            foreach (array_values($data[$direction]) as $index => $operation) {
                $path = "action.$direction.$index";
                $op = $operation['op'] ?? null;
                if (! in_array($op, array_keys(self::HEADER_OPERATIONS), true)) {
                    throw ValidationException::withMessages(["$path.op" => 'The operation must be set or remove.']);
                }
                $name = strtolower(trim((string) ($operation['name'] ?? '')));
                if (! preg_match(self::HEADER_NAME, $name)) {
                    throw ValidationException::withMessages(["$path.name" => 'The name must be 1 to 64 header name characters, such as X-Frame-Options.']);
                }
                if (self::protectedHeader($name)) {
                    throw ValidationException::withMessages(["$path.name" => "$name is set by the edge and cannot be changed by a rule."]);
                }
                if (isset($seen[$name])) {
                    throw ValidationException::withMessages(["$path.name" => "$name is changed twice in this rule."]);
                }
                $seen[$name] = true;
                if ($op === 'remove') {
                    $action[$direction][] = ['op' => 'remove', 'name' => $name];

                    continue;
                }
                $value = $operation['value'] ?? null;
                if (! is_string($value) || trim($value) === '' || strlen($value) > self::MAXIMUM_HEADER_VALUE_BYTES || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
                    throw ValidationException::withMessages(["$path.value" => 'The value must be 1 to '.self::MAXIMUM_HEADER_VALUE_BYTES.' printable characters.']);
                }
                $action[$direction][] = ['op' => 'set', 'name' => $name, 'value' => trim($value)];
            }
        }
        $count = count($action['request']) + count($action['response']);
        if ($count === 0) {
            throw ValidationException::withMessages(['action.response' => 'Add at least one header to set or remove.']);
        }
        if ($count > self::MAXIMUM_HEADER_OPERATIONS) {
            throw ValidationException::withMessages(['action.response' => 'A rule changes at most '.self::MAXIMUM_HEADER_OPERATIONS.' headers.']);
        }

        return $action;
    }

    private static function cache(array $input): array
    {
        $data = self::prefixed('action', fn () => Validator::make($input, [
            'mode' => ['required', Rule::in(array_keys(self::CACHE_MODES))],
            'edge_ttl_seconds' => ['nullable', 'integer', 'between:0,'.self::MAXIMUM_TTL_SECONDS],
            'browser_ttl_seconds' => ['nullable', 'integer', 'between:0,'.self::MAXIMUM_TTL_SECONDS],
            'respect_origin' => ['nullable', 'boolean'],
        ], [], ['edge_ttl_seconds' => 'edge TTL', 'browser_ttl_seconds' => 'browser TTL'])->validate());
        if ($data['mode'] === 'bypass') {
            return ['mode' => 'bypass'];
        }
        $action = ['mode' => 'override'];
        foreach (['edge_ttl_seconds', 'browser_ttl_seconds'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $action[$key] = (int) $data[$key];
            }
        }
        if (isset($data['respect_origin']) && $data['respect_origin'] !== '') {
            $action['respect_origin'] = (bool) $data['respect_origin'];
        }
        if (count($action) === 1) {
            throw ValidationException::withMessages(['action.edge_ttl_seconds' => 'Set an edge TTL, a browser TTL, or whether origin cache headers are respected.']);
        }

        return $action;
    }

    public static function protectedHeader(string $name): bool
    {
        $name = strtolower($name);
        if (in_array($name, self::PROTECTED_HEADERS, true)) {
            return true;
        }
        foreach (self::PROTECTED_HEADER_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        // The public response headers belong to the edge: a rule can neither set nor remove one under the current prefix.
        return str_starts_with($name, (string) app(PlatformSettings::class)->get('public_headers', 'prefix'));
    }

    /** A redirect target: a path on the same host, or an absolute http or https URL with a valid host and no user information. */
    private static function url(string $value): string
    {
        $value = trim($value);
        $invalid = fn () => throw ValidationException::withMessages(['action.url' => 'The URL must be a path such as /new or an http or https address such as https://www.example.com/new.']);
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value)) {
            $invalid();
        }
        if (str_starts_with($value, '/')) {
            if (str_starts_with($value, '//')) {
                $invalid();
            }

            return $value;
        }
        $parts = parse_url($value);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || ! preg_match(self::HOST, strtolower($parts['host']))
            || (isset($parts['path']) && ! str_starts_with($parts['path'], '/'))) {
            $invalid();
        }

        return $value;
    }

    private static function path(string $value, string $key): string
    {
        $value = trim($value);
        if (! preg_match(self::PATH, $value) || str_starts_with($value, '//')) {
            throw ValidationException::withMessages([$key => 'The prefix must be a path such as /blog, with no spaces.']);
        }

        return $value;
    }

    /** A path the origin receives: it must stay under the root, so no "." or ".." segment, encoded or not. */
    private static function rootedPath(string $value, string $key): string
    {
        $value = self::path($value, $key);
        foreach (explode('/', rawurldecode($value)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw ValidationException::withMessages([$key => 'The path cannot contain . or .. segments.']);
            }
        }

        return $value;
    }

    /** @return list<string> the paths an "equals" or "is one of" path condition names */
    private static function equalPaths(array $conditions): array
    {
        return collect($conditions)->filter(fn (array $condition): bool => $condition['field'] === 'path' && in_array($condition['operator'], ['equals', 'in_list'], true))
            ->flatMap(fn (array $condition): array => (array) $condition['value'])->values()->all();
    }

    /** @return list<string> the hosts an "equals" or "is one of" hostname condition names */
    private static function equalHosts(array $conditions): array
    {
        return collect($conditions)->filter(fn (array $condition): bool => $condition['field'] === 'hostname' && in_array($condition['operator'], ['equals', 'in_list'], true))
            ->flatMap(fn (array $condition): array => array_map('strtolower', (array) $condition['value']))->values()->all();
    }

    /** Runs a validation and moves its error keys under the given prefix. */
    private static function prefixed(string $prefix, callable $validate): array
    {
        try {
            return $validate();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["$prefix.$key" => $messages])->all());
        }
    }
}
