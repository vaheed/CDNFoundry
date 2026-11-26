<?php

namespace App\Support;

use App\Models\DomainEdgeCell;
use App\Models\RateMitigation;
use App\Models\RateWindowReport;
use App\Models\SecurityRule;
use Illuminate\Support\Facades\DB;

/**
 * Long-window rate limits across cells. Enforcement stays in each cell's shared memory; this only adds mitigations, asynchronously,
 * so it never delays a request. The error is stated in the documentation: a key is added up from the latest count each cell
 * reported for the current window, so it is caught up to one report interval, one aggregation interval and one delivery interval
 * after it crossed the threshold, and counts of a window that has ended are not carried into the next one.
 */
final class LongWindowRateLimits
{
    /** Keys one cell reports per heartbeat. */
    public const MAXIMUM_REPORTED_KEYS = 50;

    /** Active mitigations one domain may hold, so a flood of distinct keys cannot grow the table or a cell's markers. */
    public const MAXIMUM_PER_DOMAIN = 100;

    /** Mitigations one edge is sent. */
    public const MAXIMUM_PER_EDGE = 500;

    public const REPORTS_KEPT_HOURS = 50;

    /** Stores what one cell reported. Entries that do not match a long-window shared rule of a domain are ignored. */
    public static function record(string $edgeId, string $cell, array $entries): int
    {
        $rows = [];
        foreach (array_slice($entries, 0, self::MAXIMUM_REPORTED_KEYS) as $entry) {
            if (! is_array($entry) || ! isset($entry['domain_id'], $entry['rule_id'], $entry['hash'], $entry['window'], $entry['count'])
                || ! preg_match('/^[0-9a-f]{32}$/', (string) $entry['hash']) || (int) $entry['count'] < 1) {
                continue;
            }
            $rows[] = ['edge_id' => $edgeId, 'cell_name' => $cell, 'domain_id' => (int) $entry['domain_id'], 'rule_id' => (int) $entry['rule_id'], 'key_hash' => $entry['hash'],
                'window' => (int) $entry['window'], 'count' => (int) $entry['count'], 'reported_at' => now()];
        }
        if ($rows === []) {
            return 0;
        }
        $known = SecurityRule::query()->whereIn('id', array_column($rows, 'rule_id'))->pluck('domain_id', 'id');
        $rows = array_values(array_filter($rows, fn (array $row): bool => ($known[$row['rule_id']] ?? null) === $row['domain_id']));
        if ($rows !== []) {
            RateWindowReport::query()->upsert($rows, ['edge_id', 'cell_name', 'domain_id', 'rule_id', 'key_hash', 'window'], ['count', 'reported_at']);
        }

        return count($rows);
    }

    /** Adds up each key across cells and records a mitigation for every key at or over its rule's threshold. Returns how many were added. */
    public static function aggregate(): int
    {
        $added = 0;
        $rules = SecurityRule::query()->where('enabled', true)->whereNotNull('rate_limit')->get()
            ->filter(fn (SecurityRule $rule): bool => (int) ($rule->rate_limit['period_seconds'] ?? 0) >= 3600 && ($rule->rate_limit['scope'] ?? 'per_cell') === 'shared');
        foreach ($rules as $rule) {
            $period = (int) $rule->rate_limit['period_seconds'];
            $window = intdiv(time(), $period);
            $totals = RateWindowReport::query()->where('domain_id', $rule->domain_id)->where('rule_id', $rule->id)->where('window', $window)
                ->groupBy('key_hash')->havingRaw('SUM(count) >= ?', [(int) $rule->rate_limit['threshold']])->selectRaw('key_hash, SUM(count) as total')->limit(self::MAXIMUM_PER_DOMAIN)->get();
            foreach ($totals as $total) {
                $added += self::mitigate($rule, $total->key_hash) ? 1 : 0;
            }
        }
        RateWindowReport::query()->where('reported_at', '<', now()->subHours(self::REPORTS_KEPT_HOURS))->delete();
        RateMitigation::query()->where('expires_at', '<', now()->subDay())->delete();

        return $added;
    }

    private static function mitigate(SecurityRule $rule, string $hash): bool
    {
        return DB::transaction(function () use ($rule, $hash): bool {
            $existing = RateMitigation::query()->where(['domain_id' => $rule->domain_id, 'rule_id' => $rule->id, 'key_hash' => $hash])->lockForUpdate()->first();
            if ($existing !== null && $existing->expires_at->isFuture()) {
                return false;
            }
            if ($existing === null && RateMitigation::query()->where('domain_id', $rule->domain_id)->where('expires_at', '>', now())->count() >= self::MAXIMUM_PER_DOMAIN) {
                return false;
            }
            $values = ['revision' => (int) RateMitigation::query()->max('revision') + 1, 'expires_at' => now()->addSeconds((int) $rule->rate_limit['duration_seconds'])];
            if ($existing === null) {
                RateMitigation::query()->create(['domain_id' => $rule->domain_id, 'rule_id' => $rule->id, 'key_hash' => $hash, ...$values]);
            } else {
                $existing->update($values);
            }

            return true;
        });
    }

    /**
     * What an edge is given: the version and the active mitigations of the domains it serves, each with the seconds it has left.
     *
     * @return array{version: int, items: list<array{domain_id: int, rule_id: int, hash: string, seconds: int}>}
     */
    public static function delivery(string $edgeId): array
    {
        $domains = DomainEdgeCell::query()->where('edge_id', $edgeId)->pluck('domain_id');
        $items = RateMitigation::query()->whereIn('domain_id', $domains)->where('expires_at', '>', now())->orderByDesc('revision')->limit(self::MAXIMUM_PER_EDGE)->get();

        return [
            'version' => (int) RateMitigation::query()->max('revision'),
            'items' => $items->map(fn (RateMitigation $row): array => ['domain_id' => $row->domain_id, 'rule_id' => $row->rule_id, 'hash' => $row->key_hash,
                'seconds' => max(1, (int) now()->diffInSeconds($row->expires_at, false))])->values()->all(),
        ];
    }
}
