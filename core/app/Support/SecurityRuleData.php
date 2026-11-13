<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one typed shape of a domain security rule: up to five conditions that must all match, and one action.
 * Fields, operators, and actions come from fixed lists; there is no expression language and no regular expression.
 */
final class SecurityRuleData
{
    public const ACTIONS = ['block', 'challenge', 'log', 'allow', 'skip_managed'];

    public const OPERATORS = ['equals', 'not_equals', 'starts_with', 'ends_with', 'contains', 'in_list', 'not_in_list', 'greater_than', 'less_than'];

    public const MAXIMUM_CONDITIONS = 5;

    /** Hard ceiling for values in one in_list condition; the platform setting can only lower it. */
    public const LIST_VALUES_CEILING = 100;

    public const RATE_KEYS = ['client_ip' => 'Client IP', 'client_ip_path' => 'Client IP and path', 'header' => 'Header value'];

    public const RATE_PERIODS = [10 => '10 seconds', 60 => '1 minute', 600 => '10 minutes'];

    public const RATE_ACTIONS = ['block', 'challenge', 'log'];

    public const RATE_LIMIT_RULES_CEILING = 50;

    public const HTTP_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public const TLS_VERSIONS = ['TLSv1', 'TLSv1.1', 'TLSv1.2', 'TLSv1.3', 'none'];

    private const TEXT = ['equals', 'not_equals', 'starts_with', 'ends_with', 'contains', 'in_list', 'not_in_list'];

    private const CHOICE = ['equals', 'not_equals', 'in_list', 'not_in_list'];

    private const NUMBER = ['equals', 'not_equals', 'in_list', 'not_in_list', 'greater_than', 'less_than'];

    private const FLAG = ['equals'];

    /** Field name => value kind, the operators it accepts, and whether it carries a name (a header or cookie). */
    public const FIELDS = [
        'hostname' => ['kind' => 'text', 'operators' => self::TEXT],
        'path' => ['kind' => 'text', 'operators' => self::TEXT],
        'query_present' => ['kind' => 'flag', 'operators' => self::FLAG],
        'method' => ['kind' => 'method', 'operators' => self::CHOICE],
        'country' => ['kind' => 'country', 'operators' => self::CHOICE],
        'continent' => ['kind' => 'continent', 'operators' => self::CHOICE],
        'asn' => ['kind' => 'asn', 'operators' => self::NUMBER],
        'client_ip' => ['kind' => 'address', 'operators' => self::CHOICE],
        'user_agent' => ['kind' => 'text', 'operators' => self::TEXT],
        'referer' => ['kind' => 'text', 'operators' => self::TEXT],
        'referer_host' => ['kind' => 'host', 'operators' => self::CHOICE],
        'referer_present' => ['kind' => 'flag', 'operators' => self::FLAG],
        'file_extension' => ['kind' => 'extension', 'operators' => self::CHOICE],
        'header' => ['kind' => 'text', 'operators' => self::TEXT, 'named' => true],
        'cookie_present' => ['kind' => 'flag', 'operators' => self::FLAG, 'named' => true],
        'content_type' => ['kind' => 'text', 'operators' => self::TEXT],
        'body_size' => ['kind' => 'bytes', 'operators' => ['equals', 'not_equals', 'greater_than', 'less_than']],
        'tls_version' => ['kind' => 'tls', 'operators' => self::CHOICE],
        'attack_score' => ['kind' => 'score', 'operators' => self::NUMBER],
        'attack_score_sqli' => ['kind' => 'score', 'operators' => self::NUMBER],
        'attack_score_xss' => ['kind' => 'score', 'operators' => self::NUMBER],
        'attack_score_rce' => ['kind' => 'score', 'operators' => self::NUMBER],
        'leaked_password' => ['kind' => 'flag', 'operators' => self::FLAG],
        'prompt_injection_score' => ['kind' => 'score', 'operators' => self::NUMBER],
        'prompt_pii' => ['kind' => 'flag', 'operators' => self::FLAG],
        'schema_violation' => ['kind' => 'violation', 'operators' => self::CHOICE],
    ];

    /** Fields that only carry a value when the domain has the named detection set up, keyed to its domains column. */
    public const FIELD_REQUIREMENTS = [
        'prompt_injection_score' => 'ai_protection', 'prompt_pii' => 'ai_protection',
        'schema_violation' => 'api_schema', 'leaked_password' => 'credential_check',
    ];

    public const REQUIREMENT_LABELS = ['ai_protection' => 'AI protection', 'api_schema' => 'API schema', 'credential_check' => 'Credential check'];

    /** The values of the schema violation field: any violation, or one kind. "none" is a request that fits the schema. */
    public const SCHEMA_VIOLATIONS = ['any', 'none', ...ApiSchemaData::VIOLATIONS];

    public const FIELD_LABELS = [
        'hostname' => 'Hostname', 'path' => 'Path', 'query_present' => 'Query string', 'method' => 'Method', 'country' => 'Country',
        'continent' => 'Continent', 'asn' => 'ASN', 'client_ip' => 'Client IP', 'user_agent' => 'User agent', 'referer' => 'Referer', 'referer_host' => 'Referer host', 'referer_present' => 'Referer', 'file_extension' => 'File extension',
        'header' => 'Header', 'cookie_present' => 'Cookie', 'content_type' => 'Content type', 'body_size' => 'Body size (bytes)',
        'tls_version' => 'TLS version', 'attack_score' => 'Attack score', 'attack_score_sqli' => 'Attack score (SQL injection)',
        'attack_score_xss' => 'Attack score (XSS)', 'attack_score_rce' => 'Attack score (command injection)', 'leaked_password' => 'Leaked password', 'prompt_injection_score' => 'Prompt injection score', 'prompt_pii' => 'Personal data in prompt', 'schema_violation' => 'API schema violation',
    ];

    public const OPERATOR_LABELS = [
        'equals' => 'equals', 'not_equals' => 'does not equal', 'starts_with' => 'starts with', 'ends_with' => 'ends with',
        'contains' => 'contains', 'in_list' => 'is one of', 'not_in_list' => 'is not one of', 'greater_than' => 'is greater than', 'less_than' => 'is less than',
    ];

    public const ACTION_LABELS = [
        'block' => 'Block', 'challenge' => 'Challenge', 'log' => 'Log', 'allow' => 'Allow', 'skip_managed' => 'Skip managed rules',
    ];

    /** One readable line for a stored or submitted rule: "Path starts with /admin and Method equals POST". */
    public static function describe(array $conditions): string
    {
        if ($conditions === []) {
            return 'All requests';
        }

        return collect($conditions)->map(function (array $condition): string {
            $field = $condition['field'] ?? '';
            $label = self::FIELD_LABELS[$field] ?? $field;
            if (isset($condition['name'])) {
                $label .= " {$condition['name']}";
            }
            if (in_array($field, ['query_present', 'cookie_present', 'referer_present', 'leaked_password', 'prompt_pii'], true)) {
                $present = ($condition['value'] ?? null) === true;

                return $field === 'query_present' ? ($present ? 'Query string is present' : 'Query string is absent') : $label.($present ? ' is present' : ' is absent');
            }
            $value = $condition['value'] ?? '';
            $value = is_array($value) ? implode(', ', $value) : (string) $value;

            return "$label ".(self::OPERATOR_LABELS[$condition['operator'] ?? ''] ?? '')." $value";
        })->implode(' and ');
    }

    public static function maximumRateLimitRules(): int
    {
        return min(self::RATE_LIMIT_RULES_CEILING, (int) config('security.maximum_rate_limit_rules', 20));
    }

    public static function listValuesLimit(): int
    {
        return min(self::LIST_VALUES_CEILING, (int) config('security.maximum_rule_list_values', self::LIST_VALUES_CEILING));
    }

    /**
     * Validates one rule and returns it in the stored shape: a name, normalized conditions, an action, a priority, the
     * enabled flag, and the fingerprint that keeps one domain from holding the same rule twice.
     */
    public static function validate(array $input): array
    {
        $rateLimited = ($input['rate_limit'] ?? null) !== null;
        $data = Validator::make($input, [
            'name' => ['nullable', 'string', 'max:100'],
            'rate_limit' => ['nullable', 'array'],
            // Only a rate-limit rule may count every request of the domain, which is a rule with no conditions.
            'conditions' => $rateLimited ? ['present', 'array', 'max:'.self::MAXIMUM_CONDITIONS] : ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_CONDITIONS],
            'conditions.*' => ['required', 'array'],
            'action' => ['required', Rule::in(self::ACTIONS)],
            'priority' => ['required', 'integer', 'between:-1000000,1000000'],
            'enabled' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:250'],
        ])->validate();

        $conditions = [];
        foreach (array_values($data['conditions']) as $index => $condition) {
            $conditions[] = self::condition($condition, "conditions.$index");
        }
        $data['conditions'] = $conditions;
        $data['enabled'] = (bool) ($data['enabled'] ?? true);
        $data['name'] = isset($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : null;
        $data['rate_limit'] = $rateLimited ? self::rateLimit($data['rate_limit'], $data['action']) : null;
        $data['fingerprint'] = self::fingerprint($conditions, $data['action'], $data['rate_limit']);

        return $data;
    }

    /** A rule without a rate limit keeps the fingerprint it had before rate limits existed. */
    public static function fingerprint(array $conditions, string $action, ?array $rateLimit = null): string
    {
        $payload = $rateLimit === null ? [$conditions, $action] : [$conditions, $action, $rateLimit];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** The row to insert for a validated rule: the typed structures are stored as JSON text. */
    public static function row(array $rule): array
    {
        return [...$rule, 'conditions' => json_encode($rule['conditions'], JSON_THROW_ON_ERROR),
            'rate_limit' => ($rule['rate_limit'] ?? null) === null ? null : json_encode($rule['rate_limit'], JSON_THROW_ON_ERROR)];
    }

    /**
     * A rate limit counts the requests that match the rule's conditions by a key, per cell, over a fixed period. Past the
     * threshold the rule's action applies to that key for the duration, then the key is released.
     */
    private static function rateLimit(array $input, string $action): array
    {
        if (! in_array($action, self::RATE_ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'A rate-limit rule can block, challenge, or log.']);
        }
        try {
            $data = self::validateRateLimit($input);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["rate_limit.$key" => $messages])->all());
        }
        $rate = ['key' => $data['key'], 'threshold' => (int) $data['threshold'], 'period_seconds' => (int) $data['period_seconds'], 'duration_seconds' => (int) $data['duration_seconds']];
        if ($data['key'] === 'header') {
            if (! is_string($data['header_name'] ?? null) || ! preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}$/', $data['header_name'])) {
                throw ValidationException::withMessages(['rate_limit.header_name' => 'The header name must be 1 to 64 header name characters.']);
            }
            $rate['header_name'] = strtolower($data['header_name']);
        }

        return $rate;
    }

    private static function validateRateLimit(array $input): array
    {
        return Validator::make($input, [
            'key' => ['required', Rule::in(array_keys(self::RATE_KEYS))],
            'header_name' => ['nullable', 'string'],
            'threshold' => ['required', 'integer', 'between:1,100000'],
            'period_seconds' => ['required', 'integer', Rule::in(array_keys(self::RATE_PERIODS))],
            'duration_seconds' => ['required', 'integer', 'between:10,86400'],
        ], [], ['key' => 'counting key', 'period_seconds' => 'period', 'duration_seconds' => 'duration'])->validate();
    }

    /** One readable clause for a rate limit: "100 requests per 1 minute per Client IP, then Block for 300 seconds". */
    public static function describeRateLimit(array $rate, string $action): string
    {
        $key = strtolower(self::RATE_KEYS[$rate['key']] ?? $rate['key']).(isset($rate['header_name']) ? " {$rate['header_name']}" : '');
        $minutes = $rate['duration_seconds'] % 60 === 0 ? ($rate['duration_seconds'] / 60).' min' : $rate['duration_seconds'].' s';

        return "{$rate['threshold']} requests per ".self::RATE_PERIODS[$rate['period_seconds']]." per $key, then ".strtolower(self::ACTION_LABELS[$action] ?? $action)." for $minutes";
    }

    /** @return array<string, string> field => the detection it needs, for every field this domain cannot use yet */
    public static function unavailableFields(Domain $domain): array
    {
        return collect(self::FIELD_REQUIREMENTS)->filter(fn (string $column): bool => $domain->{$column} === null)
            ->map(fn (string $column): string => self::REQUIREMENT_LABELS[$column])->all();
    }

    /**
     * Refuses conditions on a field whose detection the domain has not set up: such a rule would be saved and never match.
     *
     * @param  array<int, array<string, mixed>>  $conditions  validated conditions
     */
    public static function assertFieldsAvailable(Domain $domain, array $conditions, string $path = 'conditions'): void
    {
        $unavailable = self::unavailableFields($domain);
        foreach (array_values($conditions) as $index => $condition) {
            $needs = $unavailable[$condition['field']] ?? null;
            if ($needs !== null) {
                throw ValidationException::withMessages(["$path.$index.field" => self::FIELD_LABELS[$condition['field']]." needs $needs. Set up $needs under Security first."]);
            }
        }
    }

    /** Refuses removing a detection while rules of the domain still use one of its fields. */
    public static function assertDetectionUnused(Domain $domain, string $column): void
    {
        $fields = array_keys(array_filter(self::FIELD_REQUIREMENTS, fn (string $needs): bool => $needs === $column));
        $count = $domain->securityRules()->pluck('conditions')
            ->filter(fn (mixed $conditions): bool => collect(is_string($conditions) ? json_decode($conditions, true) : $conditions)
                ->contains(fn (mixed $condition): bool => is_array($condition) && in_array($condition['field'] ?? null, $fields, true)))->count();
        abort_if($count > 0, 409, self::REQUIREMENT_LABELS[$column]." is used by $count custom ".str('rule')->plural($count).'. Delete or change '.($count === 1 ? 'it' : 'them').' first.');
    }

    /** Refuses a change that would leave the domain with more rate-limit rules than the platform allows. */
    public static function assertRateLimitCapacity(Domain $domain, int $incoming, bool $replace = false, ?int $exceptRuleId = null): void
    {
        if ($incoming === 0) {
            return;
        }
        $existing = $replace ? 0 : $domain->securityRules()->whereNotNull('rate_limit')->when($exceptRuleId, fn ($query) => $query->whereKeyNot($exceptRuleId))->count();
        abort_if($existing + $incoming > self::maximumRateLimitRules(), 409, 'The per-domain rate-limit rule limit ('.self::maximumRateLimitRules().') has been reached.');
    }

    private static function condition(array $input, string $path): array
    {
        $field = $input['field'] ?? null;
        $definition = is_string($field) ? (self::FIELDS[$field] ?? null) : null;
        if ($definition === null) {
            throw ValidationException::withMessages(["$path.field" => 'The field must be one of: '.implode(', ', array_keys(self::FIELDS)).'.']);
        }
        $operator = $input['operator'] ?? null;
        if (! is_string($operator) || ! in_array($operator, $definition['operators'], true)) {
            throw ValidationException::withMessages(["$path.operator" => 'The operator must be one of: '.implode(', ', $definition['operators']).' for '.$field.'.']);
        }
        $condition = ['field' => $field, 'operator' => $operator];
        if (($definition['named'] ?? false) === true) {
            $name = $input['name'] ?? null;
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}$/', $name)) {
                throw ValidationException::withMessages(["$path.name" => 'The name must be 1 to 64 header or cookie name characters.']);
            }
            $condition['name'] = $field === 'header' ? strtolower($name) : $name;
        }
        $condition['value'] = in_array($operator, ['in_list', 'not_in_list'], true)
            ? self::listValue($input['value'] ?? null, $definition['kind'], $path)
            : self::scalarValue($input['value'] ?? null, $definition['kind'], "$path.value");

        return $condition;
    }

    private static function listValue(mixed $value, string $kind, string $path): array
    {
        $limit = self::listValuesLimit();
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > $limit) {
            throw ValidationException::withMessages(["$path.value" => "The value must be a list of 1 to $limit items."]);
        }
        $items = array_map(fn (mixed $item): string|int => self::scalarValue($item, $kind, "$path.value"), $value);
        if (count(array_unique($items, SORT_REGULAR)) !== count($items)) {
            throw ValidationException::withMessages(["$path.value" => 'The list contains duplicate values.']);
        }

        return $items;
    }

    private static function scalarValue(mixed $value, string $kind, string $path): string|int|bool
    {
        return match ($kind) {
            'text' => self::text($value, $path),
            'flag' => is_bool($value) ? $value : throw ValidationException::withMessages([$path => 'The value must be true or false.']),
            'method' => self::choice($value, self::HTTP_METHODS, $path, strtoupper(...)),
            'country' => self::choice($value, GeoVocabulary::countries(), $path, strtoupper(...)),
            'continent' => self::choice($value, GeoVocabulary::CONTINENTS, $path, strtoupper(...)),
            'violation' => self::choice($value, self::SCHEMA_VIOLATIONS, $path, fn (string $item): string => $item),
            'tls' => self::choice($value, self::TLS_VERSIONS, $path, fn (string $item): string => $item),
            'asn' => self::integer($value, 0, 4294967295, $path),
            'host' => self::host($value, $path),
            'extension' => self::extension($value, $path),
            'bytes' => self::integer($value, 0, 1073741824, $path),
            'score' => self::integer($value, 1, 99, $path),
            'address' => self::address($value, $path),
        };
    }

    private static function host(mixed $value, string $path): string
    {
        $host = is_string($value) ? strtolower(trim($value)) : '';
        if (! preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
            throw ValidationException::withMessages([$path => 'The value must be a host name such as www.example.com.']);
        }

        return $host;
    }

    private static function extension(mixed $value, string $path): string
    {
        $extension = is_string($value) ? strtolower(ltrim(trim($value), '.')) : '';
        if (! preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            throw ValidationException::withMessages([$path => 'The value must be a file extension such as jpg, without a dot.']);
        }

        return $extension;
    }

    private static function text(mixed $value, string $path): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw ValidationException::withMessages([$path => 'The value must be 1 to 512 printable characters.']);
        }

        return $value;
    }

    private static function choice(mixed $value, array $allowed, string $path, callable $normalize): string
    {
        $normalized = is_string($value) ? $normalize(trim($value)) : null;
        if ($normalized === null || ! in_array($normalized, $allowed, true)) {
            throw ValidationException::withMessages([$path => 'The value is not supported for this field.']);
        }

        return $normalized;
    }

    private static function integer(mixed $value, int $minimum, int $maximum, string $path): int
    {
        if (is_string($value) && preg_match('/^\d{1,10}$/', $value)) {
            $value = (int) $value;
        } elseif (is_float($value) && floor($value) === $value && abs($value) < 1e11) {
            // Number inputs in the panel deliver whole numbers as floats.
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw ValidationException::withMessages([$path => "The value must be a whole number from $minimum to $maximum."]);
        }

        return $value;
    }

    /** An IPv4 or IPv6 address, or a CIDR network; always stored in canonical form. */
    private static function address(mixed $value, string $path): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (str_contains($text, '/')) {
            [$address, $prefix] = explode('/', $text, 2);
            $packed = @inet_pton($address);
            $maximum = str_contains($address, ':') ? 128 : 32;
            if ($packed !== false && ctype_digit($prefix) && (int) $prefix <= $maximum) {
                return inet_ntop($packed).'/'.(int) $prefix;
            }
        } elseif (($packed = @inet_pton($text)) !== false) {
            return inet_ntop($packed);
        }

        throw ValidationException::withMessages([$path => 'The value must be a valid IPv4 or IPv6 address or CIDR.']);
    }
}
