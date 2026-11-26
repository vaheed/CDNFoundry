<?php

namespace App\Support;

use App\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * What a rule would have matched in the last 24 hours, computed over the request fields the events store.
 *
 * The estimate is partial by construction: a condition on a field the events do not store (headers, cookies, query values, the
 * referer, TLS and body fields, detection results) is reported as unknown and left out, so the result is an upper bound over the
 * stored fields, never a prediction of zero. Events the rules never saw because of earlier blocks are in the sample as they were served.
 */
final class SecurityRuleImpact
{
    /** The fields whose value is stored in each request event. */
    private const STORED = ['hostname', 'path', 'method', 'country', 'continent', 'asn', 'user_agent', 'query_present', 'attack_score', 'attack_score_sqli', 'attack_score_xss', 'attack_score_rce'];

    private const COLUMNS = [
        'hostname' => 'lower(hostname)', 'path' => "splitByChar('?', path)[1]", 'method' => 'method', 'country' => 'country', 'continent' => 'continent',
        'asn' => 'asn', 'user_agent' => 'lower(user_agent)', 'attack_score' => 'attack_score', 'attack_score_sqli' => 'attack_score_sqli',
        'attack_score_xss' => 'attack_score_xss', 'attack_score_rce' => 'attack_score_rce',
    ];

    /** @param list<array<string, mixed>> $conditions normalized rule conditions */
    public static function estimate(Domain $domain, array $conditions, AnalyticsStore $store): array
    {
        $known = [];
        $unknown = [];
        foreach ($conditions as $condition) {
            in_array($condition['field'], self::STORED, true) ? $known[] = $condition : $unknown[] = $condition['field'];
        }
        $window = CarbonImmutable::now('UTC')->subDay();
        $parameters = [];
        $clauses = [];
        foreach ($known as $index => $condition) {
            $clauses[] = self::clause($condition, $index, $parameters);
        }
        $counted = $store->countEvents($domain->id, $window, $clauses === [] ? '1' : implode(' AND ', $clauses), $parameters);

        return [
            'partial' => true, 'window_hours' => 24, 'from' => $window->toIso8601String(),
            'events_examined' => $counted['examined'], 'matched' => $unknown === [] ? $counted['matched'] : null,
            'matched_at_most' => $counted['matched'], 'unknown_fields' => array_values(array_unique($unknown)),
            'note' => $unknown === []
                ? 'Computed over the stored request fields of the last 24 hours; events older than the raw retention are not included.'
                : 'Conditions on fields the events do not store are unknown. The count is an upper bound over the stored fields, not a prediction.',
        ];
    }

    private static function clause(array $condition, int $index, array &$parameters): string
    {
        $column = self::COLUMNS[$condition['field']] ?? null;
        if ($condition['field'] === 'query_present') {
            return ($condition['value'] === true ? '' : 'NOT ').'position(path, \'?\') > 0';
        }
        $numeric = str_starts_with($condition['field'], 'attack_score') || $condition['field'] === 'asn';
        $fold = in_array($condition['field'], ['hostname', 'user_agent'], true);
        $type = $numeric ? 'UInt64' : 'String';
        $name = "p{$index}";
        $value = $condition['value'];
        $operator = $condition['operator'];
        if (in_array($operator, ['in_list', 'not_in_list'], true)) {
            $parameters[$name] = '['.implode(',', array_map(fn ($item): string => $numeric ? (string) (int) $item : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $fold ? strtolower((string) $item) : (string) $item)."'", $value)).']';

            return "{$column} ".($operator === 'not_in_list' ? 'NOT ' : '')."IN {{$name}:Array({$type})}";
        }
        $parameters[$name] = $numeric ? (int) $value : ($fold ? strtolower((string) $value) : (string) $value);

        return match ($operator) {
            'equals' => "{$column} = {{$name}:{$type}}",
            'not_equals' => "{$column} != {{$name}:{$type}}",
            'starts_with' => "startsWith({$column}, {{$name}:String})",
            'ends_with' => "endsWith({$column}, {{$name}:String})",
            'contains' => "position({$column}, {{$name}:String}) > 0",
            'greater_than' => "{$column} > {{$name}:UInt64}",
            default => "{$column} < {{$name}:UInt64}",
        };
    }
}
