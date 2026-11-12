<?php

namespace App\Actions;

use App\Models\Domain;
use App\Models\Edge;
use App\Models\SecurityEvent;
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

    /** @param array{domain_id: int, hostname?: ?string, reason_code: string, count: int, occurred_at: int} $event */
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
                'details' => ['count' => max((int) ($stored->details['count'] ?? 0), $event['count']), 'reported' => $event['count'], 'minutes' => $slots],
                'occurred_at' => $stored->occurred_at === null ? $occurredAt : $occurredAt->max($stored->occurred_at),
            ])->save();

            if ($increase > 0 && ! in_array($event['reason_code'], SecurityConfig::NON_ESCALATING_REASONS, true)) {
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
            ->whereNotIn('reason_code', SecurityConfig::NON_ESCALATING_REASONS)
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
        $automatic = (string) $domain->automatic_security_state;
        $settings = $domain->security_settings ?? SecurityConfig::defaults();
        if ($rate >= SecurityConfig::RESTRICT_EVENTS_PER_WINDOW && str_starts_with((string) $settings['quarantine_policy'], 'automatic')
            && $automatic !== 'restricted') {
            ChangeAutomaticSecurityState::execute($domain, 'restricted', 'event_rate', ['events_in_window' => $rate, 'window_minutes' => SecurityConfig::RATE_WINDOW_MINUTES]);
        } elseif ($rate >= SecurityConfig::SUSPECT_EVENTS_PER_WINDOW && in_array($automatic, ['normal', 'recovering'], true)) {
            ChangeAutomaticSecurityState::execute($domain, 'suspected', 'event_rate', ['events_in_window' => $rate, 'window_minutes' => SecurityConfig::RATE_WINDOW_MINUTES]);
        }
    }
}
