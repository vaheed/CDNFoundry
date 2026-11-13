<?php

namespace App\Support;

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

    public const OPERATORS = ['equals', 'not_equals', 'starts_with', 'ends_with', 'contains', 'in_list', 'greater_than', 'less_than'];

    public const MAXIMUM_CONDITIONS = 5;

    /** Hard ceiling for values in one in_list condition; the platform setting can only lower it. */
    public const LIST_VALUES_CEILING = 100;

    public const HTTP_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public const TLS_VERSIONS = ['TLSv1', 'TLSv1.1', 'TLSv1.2', 'TLSv1.3', 'none'];

    private const TEXT = ['equals', 'not_equals', 'starts_with', 'ends_with', 'contains', 'in_list'];

    private const CHOICE = ['equals', 'not_equals', 'in_list'];

    private const NUMBER = ['equals', 'not_equals', 'in_list', 'greater_than', 'less_than'];

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
        'header' => ['kind' => 'text', 'operators' => self::TEXT, 'named' => true],
        'cookie_present' => ['kind' => 'flag', 'operators' => self::FLAG, 'named' => true],
        'content_type' => ['kind' => 'text', 'operators' => self::TEXT],
        'body_size' => ['kind' => 'bytes', 'operators' => ['equals', 'not_equals', 'greater_than', 'less_than']],
        'tls_version' => ['kind' => 'tls', 'operators' => self::CHOICE],
    ];

    public const FIELD_LABELS = [
        'hostname' => 'Hostname', 'path' => 'Path', 'query_present' => 'Query string', 'method' => 'Method', 'country' => 'Country',
        'continent' => 'Continent', 'asn' => 'ASN', 'client_ip' => 'Client IP', 'user_agent' => 'User agent', 'referer' => 'Referer',
        'header' => 'Header', 'cookie_present' => 'Cookie', 'content_type' => 'Content type', 'body_size' => 'Body size (bytes)',
        'tls_version' => 'TLS version',
    ];

    public const OPERATOR_LABELS = [
        'equals' => 'equals', 'not_equals' => 'does not equal', 'starts_with' => 'starts with', 'ends_with' => 'ends with',
        'contains' => 'contains', 'in_list' => 'is one of', 'greater_than' => 'is greater than', 'less_than' => 'is less than',
    ];

    public const ACTION_LABELS = [
        'block' => 'Block', 'challenge' => 'Challenge', 'log' => 'Log', 'allow' => 'Allow', 'skip_managed' => 'Skip managed rules',
    ];

    /** One readable line for a stored or submitted rule: "Path starts with /admin and Method equals POST". */
    public static function describe(array $conditions): string
    {
        return collect($conditions)->map(function (array $condition): string {
            $field = $condition['field'] ?? '';
            $label = self::FIELD_LABELS[$field] ?? $field;
            if (isset($condition['name'])) {
                $label .= " {$condition['name']}";
            }
            if (in_array($field, ['query_present', 'cookie_present'], true)) {
                $present = ($condition['value'] ?? null) === true;

                return $field === 'query_present' ? ($present ? 'Query string is present' : 'Query string is absent') : $label.($present ? ' is present' : ' is absent');
            }
            $value = $condition['value'] ?? '';
            $value = is_array($value) ? implode(', ', $value) : (string) $value;

            return "$label ".(self::OPERATOR_LABELS[$condition['operator'] ?? ''] ?? '')." $value";
        })->implode(' and ');
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
        $data = Validator::make($input, [
            'name' => ['nullable', 'string', 'max:100'],
            'conditions' => ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_CONDITIONS],
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
        $data['fingerprint'] = self::fingerprint($conditions, $data['action']);

        return $data;
    }

    public static function fingerprint(array $conditions, string $action): string
    {
        return hash('sha256', json_encode([$conditions, $action], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
        $condition['value'] = $operator === 'in_list'
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
            'tls' => self::choice($value, self::TLS_VERSIONS, $path, fn (string $item): string => $item),
            'asn' => self::integer($value, 0, 4294967295, $path),
            'bytes' => self::integer($value, 0, 1073741824, $path),
            'address' => self::address($value, $path),
        };
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
