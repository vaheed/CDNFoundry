<?php

namespace App\Support;

/**
 * Explains one stored request event: which stage of the cell acted, which rules and signals were involved, and which signals
 * the event cannot report. It is built only from fields the event already stores, so it adds no bytes to the log line and
 * holds no request data beyond what the event holds.
 */
final class DecisionTrace
{
    /** Signals no event carries; each is named so an absent value is never read as a clean one. */
    public const UNAVAILABLE = [
        'reputation_band' => 'Client reputation runs in shadow mode and is not logged per request.',
        'bot_components' => 'Bot signals are not logged per request.',
        'platform_datasets' => 'Signed platform datasets are not delivered to cells, so no dataset version applies.',
    ];

    /**
     * @param  array<string, mixed>  $event  a row read from edge_events
     * @return array{stage: string, acted: ?string, rules: list<string>, shadow_rules: list<string>, managed_rule_ids: list<int>, scores: array<string, int>, client_key: ?string, snapshot_sequence: ?int, unavailable: list<string>}
     */
    public static function of(array $event): array
    {
        $reason = (string) ($event['security_reason'] ?? '');
        $rule = (string) ($event['security_rule'] ?? '');
        $stage = match (true) {
            $reason === '' && $rule === '' => 'none',
            str_starts_with($reason, 'waf_') || $rule === 'waf' => 'managed_waf',
            str_contains($reason, 'rate_limited') || in_array($reason, ['client_rate_exceeded', 'domain_rate_exceeded', 'client_temporarily_blocked'], true) => 'rate_limit',
            str_starts_with($reason, 'challenge_') => 'challenge',
            str_starts_with($reason, 'platform_rule_') || preg_match('/^p\d+$/', $rule) === 1 => 'platform_rule',
            str_starts_with($reason, 'custom_rule_') || preg_match('/^r\d+$/', $rule) === 1 => 'domain_rule',
            str_starts_with($reason, 'sensitive_data_') => 'sensitive_data',
            str_starts_with($reason, 'schema_') || $reason === 'jwt_invalid' => 'api_protection',
            default => 'platform_limit',
        };
        $scores = array_filter([
            'attack' => (int) ($event['attack_score'] ?? 0), 'sqli' => (int) ($event['attack_score_sqli'] ?? 0),
            'xss' => (int) ($event['attack_score_xss'] ?? 0), 'rce' => (int) ($event['attack_score_rce'] ?? 0), 'waf_anomaly' => (int) ($event['waf_score'] ?? 0),
        ], fn (int $score): bool => $score > 0);

        return [
            'stage' => $stage,
            'acted' => $reason === '' ? null : $reason,
            'rules' => array_values(array_filter([$rule, (string) ($event['request_rule'] ?? '')])),
            'shadow_rules' => self::list((string) ($event['shadow_rule'] ?? '')),
            'managed_rule_ids' => array_map('intval', self::list((string) ($event['waf_rule_ids'] ?? ''))),
            'scores' => $scores,
            // The keyed hash differs on every node, so it follows one client across the cells of one edge and no further. Events
            // written before the cells logged these fields, and requests that no rule touched, carry neither.
            'client_key' => filled($event['client_key'] ?? null) ? (string) $event['client_key'] : null,
            'snapshot_sequence' => (int) ($event['snapshot_sequence'] ?? 0) > 0 ? (int) $event['snapshot_sequence'] : null,
            'unavailable' => [
                ...(filled($event['client_key'] ?? null) ? [] : ['client_key']),
                ...((int) ($event['snapshot_sequence'] ?? 0) > 0 ? [] : ['snapshot_sequence']),
                ...array_keys(self::UNAVAILABLE),
            ],
        ];
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(explode(',', $value), fn (string $item): bool => $item !== ''));
    }
}
