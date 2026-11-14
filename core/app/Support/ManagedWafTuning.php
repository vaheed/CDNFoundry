<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A domain's adjustments to the managed rules, on top of its protection level: a paranoia level, an anomaly threshold,
 * categories switched off, and single rules disabled or set to log only. Rule ids come from the catalog of the pinned
 * ruleset, so a customer can never name a rule the cells do not run.
 */
final class ManagedWafTuning
{
    public const MAXIMUM_RULES = 100;

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
        return ['paranoia_level' => null, 'inbound_threshold' => null, 'disabled_categories' => [], 'disabled_rules' => [], 'log_only_rules' => []];
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
        ], ['in' => 'The :attribute is not a rule or category of the pinned ruleset.'])->validate();
        $tuning = [
            'paranoia_level' => isset($data['paranoia_level']) ? (int) $data['paranoia_level'] : null,
            'inbound_threshold' => isset($data['inbound_threshold']) ? (int) $data['inbound_threshold'] : null,
            'disabled_categories' => self::sorted($data['disabled_categories'] ?? []),
            'disabled_rules' => self::sortedIds($data['disabled_rules'] ?? []),
            'log_only_rules' => self::sortedIds($data['log_only_rules'] ?? []),
        ];
        if (array_intersect($tuning['disabled_rules'], $tuning['log_only_rules']) !== []) {
            throw ValidationException::withMessages(['log_only_rules' => 'A rule cannot be both disabled and log only.']);
        }

        return $tuning === self::empty() ? null : $tuning;
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
