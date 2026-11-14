<?php

namespace App\Actions;

use App\Models\Domain;
use App\Models\Edge;
use App\Models\SecurityEvent;
use App\Support\SecurityAlertData;
use App\Support\SecurityConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records one noisy-domain report from an edge and, when the recent rate of escalating events crosses a threshold, moves
 * the domain to a stricter automatic state. The cell reports a cumulative counter, so the rate comes from the increase
 * since the previous report of the same edge and reason, kept in per-minute slots for a bounded window.
 */
class RecordSecurityReport
{
    private const SLOT_RETENTION_MINUTES = 15;

    /** @param array{domain_id: int, hostname?: ?string, reason_code: string, count: int, occurred_at: int, clients?: int} $event */
    public static function execute(Edge $edge, array $event): void
    {
        DB::transaction(function () use ($edge, $event): void {
            $domain = Domain::query()->where('lifecycle_state', 'active')
                ->whereHas('edgeCells', fn ($cells) => $cells->where('edge_id', $edge->id)
                    ->where(fn ($assigned) => $assigned->whereNotNull('active_cell_id')->orWhereNotNull('target_cell_id')))
                ->lockForUpdate()->find($event['domain_id']);
            if ($domain === null || (($event['hostname'] ?? null) !== null && ! $domain->dnsRecords()->where('mode', 'proxied')->where('name', $event['hostname'])->exists())) {
                return;
            }
            $occurredAt = CarbonImmutable::createFromTimestamp($event['occurred_at']);
            $scope = ['domain_id' => $domain->id, 'edge_id' => $edge->id, 'reason_code' => $event['reason_code']];
            $reportedBefore = (int) (SecurityEvent::query()->where($scope)->latest('id')->first()?->details['reported'] ?? 0);
            $increase = $event['count'] >= $reportedBefore ? $event['count'] - $reportedBefore : $event['count'];

            // One row per domain, edge, reason, and hour: later reports raise the row, never add one.
            $stored = SecurityEvent::query()->firstOrNew([...$scope, 'bucket_at' => $occurredAt->startOfHour()]);
            $slots = (array) ($stored->details['minutes'] ?? []);
            if ($increase > 0) {
                $minute = (string) intdiv($occurredAt->timestamp, 60);
                $slots[$minute] = (int) ($slots[$minute] ?? 0) + $increase;
            }
            $oldest = intdiv(now()->timestamp, 60) - self::SLOT_RETENTION_MINUTES;
            $slots = array_filter($slots, fn (int $value, string $minute): bool => (int) $minute >= $oldest, ARRAY_FILTER_USE_BOTH);
            $stored->fill([
                'hostname' => $event['hostname'] ?? $stored->hostname, 'state' => $domain->effectiveSecurityState(),
                // `clients` is how many distinct clients the cell saw cause escalating events of the domain recently. A cell that
                // does not report it gives no distributed evidence, and the domain is never escalated on its events alone.
                'details' => ['count' => max((int) ($stored->details['count'] ?? 0), $event['count']), 'reported' => $event['count'], 'minutes' => $slots,
                    'clients' => (int) ($event['clients'] ?? 0), 'clients_at' => now()->timestamp],
                'occurred_at' => $stored->occurred_at === null ? $occurredAt : $occurredAt->max($stored->occurred_at),
            ])->save();

            if ($increase > 0 && ! in_array($event['reason_code'], SecurityConfig::nonEscalatingReasons(), true)) {
                self::escalateOnRate($domain);
            }
        });
    }

    /** Events of escalating reasons across every edge in the rate window. */
    public static function recentRate(Domain $domain): int
    {
        $since = intdiv(now()->timestamp, 60) - SecurityConfig::RATE_WINDOW_MINUTES;
        $total = 0;
        SecurityEvent::query()->where('domain_id', $domain->id)->whereNotNull('bucket_at')
            ->where('occurred_at', '>=', now()->subMinutes(self::SLOT_RETENTION_MINUTES))
            ->whereNotIn('reason_code', SecurityConfig::nonEscalatingReasons())
            ->limit(1000)->get(['details'])
            ->each(function (SecurityEvent $row) use (&$total, $since): void {
                foreach ((array) ($row->details['minutes'] ?? []) as $minute => $events) {
                    if ((int) $minute > $since) {
                        $total += (int) $events;
                    }
                }
            });

        return $total;
    }

    /**
     * The most distinct clients any edge reported for the domain's escalating events within the rate window. Each cell counts
     * the clients of all escalating reasons together, so the largest report is the best evidence, and adding reports from
     * several edges would count a client twice.
     */
    public static function distinctClients(Domain $domain): int
    {
        $since = now()->subMinutes(SecurityConfig::RATE_WINDOW_MINUTES)->timestamp;
        $most = 0;
        SecurityEvent::query()->where('domain_id', $domain->id)->whereNotNull('bucket_at')
            ->where('occurred_at', '>=', now()->subMinutes(self::SLOT_RETENTION_MINUTES))
            ->whereNotIn('reason_code', SecurityConfig::nonEscalatingReasons())
            ->limit(1000)->get(['details'])
            ->each(function (SecurityEvent $row) use (&$most, $since): void {
                if ((int) ($row->details['clients_at'] ?? 0) >= $since) {
                    $most = max($most, (int) ($row->details['clients'] ?? 0));
                }
            });

        return $most;
    }

    /**
     * Mitigated requests the edges reported for the domain over the last $minutes minutes, from the same per-minute slots.
     * Unlike the escalation rate it counts every enforcing reason, including a domain's own rules.
     */
    public static function mitigationsInWindow(Domain $domain, int $minutes): int
    {
        $since = intdiv(now()->timestamp, 60) - $minutes;
        $total = 0;
        SecurityEvent::query()->where('domain_id', $domain->id)->whereNotNull('bucket_at')
            ->where('occurred_at', '>=', now()->subMinutes(self::SLOT_RETENTION_MINUTES))
            ->whereNotIn('reason_code', SecurityAlertData::NOT_MITIGATIONS)
            ->limit(1000)->get(['details'])
            ->each(function (SecurityEvent $row) use (&$total, $since): void {
                foreach ((array) ($row->details['minutes'] ?? []) as $minute => $events) {
                    if ((int) $minute > $since) {
                        $total += (int) $events;
                    }
                }
            });

        return $total;
    }

    private static function escalateOnRate(Domain $domain): void
    {
        // An operator's restriction or quarantine already holds the domain at least as strict.
        if (! in_array($domain->security_state, ['normal', 'recovering'], true)) {
            return;
        }
        $rate = self::recentRate($domain);
        // Many events from a few clients are contained on the cell, per client. The whole domain tightens only for an attack that
        // many clients take part in, so one noisy client cannot make the domain stricter for everyone else.
        $clients = self::distinctClients($domain);
        if ($clients < SecurityConfig::DISTRIBUTED_CLIENTS_MINIMUM) {
            return;
        }
        $automatic = (string) $domain->automatic_security_state;
        $settings = $domain->security_settings ?? SecurityConfig::defaults();
        if ($rate >= SecurityConfig::RESTRICT_EVENTS_PER_WINDOW && str_starts_with((string) $settings['quarantine_policy'], 'automatic')
            && $automatic !== 'restricted') {
            ChangeAutomaticSecurityState::execute($domain, 'restricted', 'event_rate', ['events_in_window' => $rate, 'distinct_clients' => $clients, 'window_minutes' => SecurityConfig::RATE_WINDOW_MINUTES]);
        } elseif ($rate >= SecurityConfig::SUSPECT_EVENTS_PER_WINDOW && in_array($automatic, ['normal', 'recovering'], true)) {
            ChangeAutomaticSecurityState::execute($domain, 'suspected', 'event_rate', ['events_in_window' => $rate, 'distinct_clients' => $clients, 'window_minutes' => SecurityConfig::RATE_WINDOW_MINUTES]);
        }
    }
}
