<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A domain's adjustments to the managed rules, on top of its protection level: a paranoia level, an anomaly threshold,
 * categories switched off, single rules disabled or set to log only, and rules or categories switched off only for a path prefix or method. Rule ids come from the catalog of the pinned
 * ruleset, so a customer can never name a rule the cells do not run.
 */
final class ManagedWafTuning
{
    public const MAXIMUM_RULES = 100;

    public const MAXIMUM_SCOPED_EXCLUSIONS = 20;

    public const MAXIMUM_SCOPED_TARGETS = 20;

    public const SCOPE_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @return array{ruleset: string, categories: array<string, array{key: string, label: string}>, rules: list<array{id: int, category: string, message: string, paranoia_level: int}>} */
    public static function catalog(): array
    {
        static $catalog = null;

        return $catalog ??= json_decode((string) file_get_contents(resource_path('contracts/crs-rules.json')), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array<int, array{id: int, category: string, message: string, paranoia_level: int}> keyed by rule id */
    public static function rulesById(): array
    {
        static $rules = null;

        return $rules ??= collect(self::catalog()['rules'])->keyBy('id')->all();
    }

    /** @return array<string, string> category key => its three-digit rule prefix */
    public static function categoryPrefixes(): array
    {
        return collect(self::catalog()['categories'])->mapWithKeys(fn (array $category, string $prefix): array => [$category['key'] => $prefix])->all();
    }

    public static function categoryLabels(): array
    {
        return collect(self::catalog()['categories'])->mapWithKeys(fn (array $category): array => [$category['key'] => $category['label']])->all();
    }

    public static function empty(): array
    {
        return ['paranoia_level' => null, 'inbound_threshold' => null, 'disabled_categories' => [], 'disabled_rules' => [], 'log_only_rules' => [], 'scoped_exclusions' => []];
    }

    public static function normalize(?array $stored): array
    {
        return [...self::empty(), ...($stored ?? [])];
    }

    /** Validates a submitted tuning and returns it in the stored shape, or null when it changes nothing. */
    public static function validate(array $input): ?array
    {
        $data = Validator::make($input, [
            'paranoia_level' => ['nullable', 'integer', 'between:1,4'],
            'inbound_threshold' => ['nullable', 'integer', 'between:1,100'],
            'disabled_categories' => ['nullable', 'array', 'max:'.count(self::categoryPrefixes())],
            'disabled_categories.*' => ['string', Rule::in(array_keys(self::categoryPrefixes()))],
            'disabled_rules' => ['nullable', 'array', 'max:'.self::MAXIMUM_RULES],
            'disabled_rules.*' => ['integer', Rule::in(array_keys(self::rulesById()))],
            'log_only_rules' => ['nullable', 'array', 'max:'.self::MAXIMUM_RULES],
            'log_only_rules.*' => ['integer', Rule::in(array_keys(self::rulesById()))],
            'scoped_exclusions' => ['nullable', 'array', 'max:'.self::MAXIMUM_SCOPED_EXCLUSIONS],
            'scoped_exclusions.*' => ['array:rules,categories,path_prefix,methods'],
            'scoped_exclusions.*.rules' => ['nullable', 'array', 'max:'.self::MAXIMUM_SCOPED_TARGETS],
            'scoped_exclusions.*.rules.*' => ['integer', Rule::in(array_keys(self::rulesById()))],
            'scoped_exclusions.*.categories' => ['nullable', 'array', 'max:'.self::MAXIMUM_SCOPED_TARGETS],
            'scoped_exclusions.*.categories.*' => ['string', Rule::in(array_keys(self::categoryPrefixes()))],
            // A normalized path prefix: no percent escapes, no dot segments, no empty segments, so the cell's comparison is exact.
            'scoped_exclusions.*.path_prefix' => ['nullable', 'string', 'max:200', 'regex:#^/([A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*/?)?$#', 'not_regex:#(^|/)\.\.?(/|$)#'],
            'scoped_exclusions.*.methods' => ['nullable', 'array', 'max:'.count(self::SCOPE_METHODS)],
            'scoped_exclusions.*.methods.*' => ['string', Rule::in(self::SCOPE_METHODS)],
        ], ['in' => 'The :attribute is not a rule or category of the pinned ruleset.'])->validate();
        $tuning = [
            'paranoia_level' => isset($data['paranoia_level']) ? (int) $data['paranoia_level'] : null,
            'inbound_threshold' => isset($data['inbound_threshold']) ? (int) $data['inbound_threshold'] : null,
            'disabled_categories' => self::sorted($data['disabled_categories'] ?? []),
            'disabled_rules' => self::sortedIds($data['disabled_rules'] ?? []),
            'log_only_rules' => self::sortedIds($data['log_only_rules'] ?? []),
            'scoped_exclusions' => self::scopedExclusions($data['scoped_exclusions'] ?? []),
        ];
        if (array_intersect($tuning['disabled_rules'], $tuning['log_only_rules']) !== []) {
            throw ValidationException::withMessages(['log_only_rules' => 'A rule cannot be both disabled and log only.']);
        }

        return $tuning === self::empty() ? null : $tuning;
    }

    /**
     * Rules or categories switched off only where a request's method or path matches. Each entry names at least one rule or
     * category and at least one scope: an exclusion that applies everywhere is "Turn off", which already exists.
     *
     * @return list<array{rules: list<int>, categories: list<string>, path_prefix: ?string, methods: list<string>}>
     */
    private static function scopedExclusions(array $entries): array
    {
        $normalized = [];
        foreach (array_values($entries) as $index => $entry) {
            $item = [
                'rules' => self::sortedIds($entry['rules'] ?? []),
                'categories' => self::sorted($entry['categories'] ?? []),
                'path_prefix' => ($entry['path_prefix'] ?? '') === '' ? null : (string) $entry['path_prefix'],
                'methods' => self::sorted($entry['methods'] ?? []),
            ];
            if ($item['rules'] === [] && $item['categories'] === []) {
                throw ValidationException::withMessages(["scoped_exclusions.{$index}.rules" => 'Name at least one rule or category to exclude.']);
            }
            if ($item['path_prefix'] === null && $item['methods'] === []) {
                throw ValidationException::withMessages(["scoped_exclusions.{$index}.path_prefix" => 'Give a path prefix or a method. To turn a rule off everywhere, use Turn off.']);
            }
            $normalized[json_encode($item)] = $item;
        }
        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * What an edge cell needs: the rule-id prefixes of switched-off categories, the individually disabled rules outside those
     * categories, and the log-only rules. Everything is bounded by the catalog.
     */
    public static function forEdge(?array $stored): array
    {
        $tuning = self::normalize($stored);
        $prefixes = self::categoryPrefixes();
        $off = array_values(array_map(fn (string $category): string => $prefixes[$category], $tuning['disabled_categories']));
        $rules = self::rulesById();
        $inOffCategory = fn (int $id): bool => in_array(substr((string) $id, 0, 3), $off, true);

        return [
            'off_categories' => $off,
            'off_rules' => array_values(array_filter($tuning['disabled_rules'], fn (int $id): bool => isset($rules[$id]) && ! $inOffCategory($id))),
            'log_only_rules' => array_values(array_filter($tuning['log_only_rules'], fn (int $id): bool => isset($rules[$id]) && ! $inOffCategory($id))),
            // The cell removes these from the scoring pass of a request only when its method and normalized path match the scope.
            'scoped_exclusions' => array_values(array_map(fn (array $entry): array => [
                'rules' => array_values(array_filter($entry['rules'], fn (int $id): bool => isset($rules[$id]))),
                'categories' => array_values(array_map(fn (string $category): string => $prefixes[$category], array_filter($entry['categories'], fn (string $category): bool => isset($prefixes[$category])))),
                'path_prefix' => $entry['path_prefix'],
                'methods' => $entry['methods'],
            ], $tuning['scoped_exclusions'])),
        ];
    }

    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private static function sortedIds(array $values): array
    {
        return array_map('intval', self::sorted(array_map('intval', $values)));
    }
}
