<?php

namespace App\Support;

use App\Models\ClientReputation as ClientReputationRow;
use App\Models\ClientReputationReport;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shared client reputation in its shadow stage: it records what the cells report and keeps a band per client, and it changes no
 * request. A client is banded only when at least two cells report it, the events are a large share of what the cells saw from it
 * (a shared address with a few attackers among many browsers stays clean), and the total is over a floor. The score is the larger
 * of this window's events and the earlier score halved every hour, so a band fades without counting a window twice.
 */
final class ClientReputation
{
    /** Clients one cell reports per heartbeat. */
    public const MAXIMUM_REPORTED_CLIENTS = 50;

    /** Seconds of reports added up. */
    public const WINDOW_SECONDS = 300;

    /** Cells that must report a client before it is banded. */
    public const MINIMUM_CELLS = 2;

    /** Events across cells in the window for a client to be watched, elevated or high. */
    public const BANDS = ['watch' => 50, 'elevated' => 200, 'high' => 1000];

    /** Share of a client's requests that were escalating events, in percent, at or above which it is banded. */
    public const MINIMUM_EVENT_SHARE_PERCENT = 50;

    public const HALF_LIFE_MINUTES = 60;

    /** Rows kept in the band table; the lowest scores go first. */
    public const MAXIMUM_CLIENTS = 5000;

    public const REPORTS_KEPT_MINUTES = 60;

    public const CLIENTS_KEPT_DAYS = 7;

    /** Stores what one cell reported, for enrolled domains only. A report for any other domain is dropped, never stored. */
    public static function record(string $edgeId, string $cell, array $entries): int
    {
        $rows = [];
        foreach (array_slice($entries, 0, self::MAXIMUM_REPORTED_CLIENTS) as $entry) {
            if (! is_array($entry) || ! isset($entry['domain_id'], $entry['client'], $entry['events'], $entry['requests'])
                || ! is_string($entry['client']) || ! self::validClient($entry['client']) || (int) $entry['events'] < 1 || (int) $entry['requests'] < 0) {
                continue;
            }
            $rows[(int) $entry['domain_id'].'|'.$entry['client']] = ['edge_id' => $edgeId, 'cell_name' => $cell, 'domain_id' => (int) $entry['domain_id'], 'client_key' => $entry['client'],
                'events' => min((int) $entry['events'], 2147483647), 'requests' => (int) $entry['requests'], 'reported_at' => now()];
        }
        if ($rows === []) {
            return 0;
        }
        $enrolled = Domain::query()->whereIn('id', array_unique(array_column($rows, 'domain_id')))->where('reputation_sharing', true)->pluck('id')->all();
        $rows = array_values(array_filter($rows, fn (array $row): bool => in_array($row['domain_id'], $enrolled, true)));
        if ($rows !== []) {
            ClientReputationReport::query()->upsert($rows, ['edge_id', 'cell_name', 'domain_id', 'client_key'], ['events', 'requests', 'reported_at']);
        }

        return count($rows);
    }

    /** An IPv4 address or an IPv6 prefix as a cell writes its client key; anything else is not stored. */
    public static function validClient(string $key): bool
    {
        if (strlen($key) > 64) {
            return false;
        }
        $address = explode('/', $key, 2);
        if (count($address) === 1) {
            return filter_var($address[0], FILTER_VALIDATE_IP) !== false;
        }

        return filter_var($address[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && ctype_digit($address[1]) && (int) $address[1] >= 1 && (int) $address[1] <= 128;
    }

    /** Recomputes every client's band from the last window. Returns how many clients are banded. */
    public static function aggregate(): int
    {
        $now = CarbonImmutable::now();
        $totals = ClientReputationReport::query()
            ->join('domains', fn ($join) => $join->on('domains.id', '=', 'client_reputation_reports.domain_id')->where('domains.reputation_sharing', true)->whereNull('domains.deleted_at'))
            ->where('client_reputation_reports.reported_at', '>=', $now->subSeconds(self::WINDOW_SECONDS))
            ->groupBy('client_reputation_reports.client_key')
            ->selectRaw('client_reputation_reports.client_key as client_key, SUM(events) as events, SUM(requests) as requests, COUNT(DISTINCT client_reputation_reports.domain_id) as domains, COUNT(DISTINCT client_reputation_reports.edge_id) as edges, COUNT(DISTINCT client_reputation_reports.edge_id || \'/\' || client_reputation_reports.cell_name) as cells')
            ->orderByRaw('SUM(events) desc')->limit(self::MAXIMUM_CLIENTS)->toBase()->get()->keyBy('client_key');

        $previous = ClientReputationRow::query()->get()->keyBy('client_key');
        DB::transaction(function () use ($totals, $previous, $now): void {
            foreach ($totals->keys()->merge($previous->keys())->unique() as $key) {
                $total = $totals->get($key);
                $before = $previous->get($key);
                $window = 0;
                if ($total !== null && (int) $total->cells >= self::MINIMUM_CELLS) {
                    $events = (int) $total->events;
                    $seen = max($events, (int) $total->requests);
                    $window = $seen > 0 && $events * 100 >= self::MINIMUM_EVENT_SHARE_PERCENT * $seen ? $events : 0;
                }
                $decayed = $before === null ? 0 : (int) floor($before->score * 0.5 ** (max(0, $before->updated_at->diffInSeconds($now)) / 60 / self::HALF_LIFE_MINUTES));
                $score = max($window, $decayed);
                $band = self::band($score);
                if ($band === null || ($before !== null && $before->last_seen_at->lt($now->subDays(self::CLIENTS_KEPT_DAYS)) && $window === 0)) {
                    $before?->delete();

                    continue;
                }
                $values = ['score' => $score, 'band' => $band];
                if ($window > 0) {
                    $values += ['events' => (int) $total->events, 'requests' => (int) $total->requests, 'domain_count' => (int) $total->domains, 'cell_count' => (int) $total->cells,
                        'edge_count' => (int) $total->edges, 'last_seen_at' => $now];
                }
                if ($before === null) {
                    ClientReputationRow::query()->create(['client_key' => $key, 'first_seen_at' => $now, 'last_seen_at' => $now] + $values);
                } else {
                    $before->update($values);
                }
            }
            ClientReputationReport::query()->where('reported_at', '<', $now->subMinutes(self::REPORTS_KEPT_MINUTES))->delete();
            ClientReputationReport::query()->whereNotIn('domain_id', Domain::query()->where('reputation_sharing', true)->select('id'))->delete();
            $excess = ClientReputationRow::query()->orderByDesc('score')->orderByDesc('id')->skip(self::MAXIMUM_CLIENTS)->take(PHP_INT_MAX)->pluck('id');
            ClientReputationRow::query()->whereIn('id', $excess)->delete();
            ClientReputationRow::query()->where('last_seen_at', '<', $now->subDays(self::CLIENTS_KEPT_DAYS))->delete();
        });

        return ClientReputationRow::query()->count();
    }

    public static function band(int $score): ?string
    {
        $found = null;
        foreach (self::BANDS as $name => $floor) {
            if ($score >= $floor) {
                $found = $name;
            }
        }

        return $found;
    }
}
