<?php

namespace App\Support;

/**
 * Explains one stored request event: which stage of the cell acted, which rules and signals were involved, and which signals
 * the event cannot report. It is built only from fields the event already stores, so it adds no bytes to the log line and
 * holds no request data beyond what the event holds.
 */
final class DecisionTrace
{
    /** Signals the event does not carry; each is named so an absent value is never read as a clean one. */
    public const UNAVAILABLE = [
        'client_key' => 'The cell does not log the rate-limit key of a request.',
        'reputation_band' => 'Client reputation runs in shadow mode and is not logged per request.',
        'bot_components' => 'Bot signals are not logged per request.',
        'dataset_versions' => 'The dataset versions a request was matched against are not logged per request.',
    ];

    /**
     * @param  array<string, mixed>  $event  a row read from edge_events
     * @return array{stage: string, acted: ?string, rules: list<string>, shadow_rules: list<string>, managed_rule_ids: list<int>, scores: array<string, int>, unavailable: list<string>}
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
            'unavailable' => array_keys(self::UNAVAILABLE),
        ];
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(explode(',', $value), fn (string $item): bool => $item !== ''));
    }
}
