<?php

namespace App\Support;

use App\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * Groups the blocked requests of a domain into attack episodes. An episode runs from the first blocked minute to the last one
 * before a quiet spell of SecurityAlertData::EPISODE_QUIET_MINUTES, the same rule that sends one alert per episode, and it
 * counts only when it blocked at least MINIMUM_BLOCKED requests. Everything is read from ClickHouse for one bounded window.
 */
final class AttackEpisodes
{
    public const MINIMUM_BLOCKED = 20;

    /**
     * @param  list<array{minute: string, blocked: int}>  $minutes  oldest first
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, blocked: int, peak_per_minute: int}> newest first
     */
    public static function group(array $minutes): array
    {
        $episodes = [];
        $current = null;
        foreach ($minutes as $row) {
            $minute = CarbonImmutable::parse($row['minute'], 'UTC');
            if ($current !== null && $current['end']->diffInMinutes($minute) > SecurityAlertData::EPISODE_QUIET_MINUTES) {
                $episodes[] = $current;
                $current = null;
            }
            $current = $current === null
                ? ['start' => $minute, 'end' => $minute, 'blocked' => $row['blocked'], 'peak_per_minute' => $row['blocked']]
                : ['start' => $current['start'], 'end' => $minute, 'blocked' => $current['blocked'] + $row['blocked'], 'peak_per_minute' => max($current['peak_per_minute'], $row['blocked'])];
        }
        if ($current !== null) {
            $episodes[] = $current;
        }

        return array_slice(array_reverse(array_values(array_filter($episodes, fn (array $episode): bool => $episode['blocked'] >= self::MINIMUM_BLOCKED))), 0, AnalyticsStore::EPISODES_LISTED);
    }

    /**
     * The episodes of the window, each with its top client keys, paths, and rules.
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(Domain $domain, array $range, AnalyticsStore $store): array
    {
        $episodes = [];
        foreach (self::group($store->blockedMinutes($domain, $range)) as $episode) {
            $window = ['from' => $episode['start'], 'to' => $episode['end']->addMinute(), 'raw' => true];
            $episodes[] = [
                'start' => $episode['start']->toIso8601String(), 'end' => $episode['end']->addMinute()->toIso8601String(),
                'duration_minutes' => $episode['start']->diffInMinutes($episode['end']) + 1,
                'blocked' => $episode['blocked'], 'peak_per_minute' => $episode['peak_per_minute'],
                'top_client_keys' => $store->topBlocked($domain, $window, 'client_key'),
                'top_paths' => $store->topBlocked($domain, $window, 'path'),
                'top_rules' => $store->topBlocked($domain, $window, 'security_rule'),
            ];
        }

        return $episodes;
    }
}
