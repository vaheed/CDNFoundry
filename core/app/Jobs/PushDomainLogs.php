<?php

namespace App\Jobs;

use App\Models\LogPushDestination;
use App\Support\LogPush\LogDatasets;
use App\Support\LogPush\LogRowReader;
use App\Support\LogPush\LogPushSender;
use App\Support\Notifications\EventPublisher;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One bounded push for one destination: reads the domain's own rows after its cursor, sends them as gzip NDJSON, and moves the
 * cursor only after the destination accepted the batch. A failure keeps the cursor and backs off. Idle destinations write nothing.
 */
class PushDomainLogs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const ZERO_ID = '00000000-0000-0000-0000-000000000000';

    /** Events newer than this are left for the next run, so a late insert is not skipped by the cursor. */
    private const INGEST_MARGIN_SECONDS = 30;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public string $destinationId)
    {
        $this->onQueue('log-push');
    }

    public function uniqueId(): string
    {
        return $this->destinationId;
    }

    public function handle(LogRowReader $store, LogPushSender $sender, PlatformSettings $settings): void
    {
        $destination = LogPushDestination::query()->with('domain')->find($this->destinationId);
        if ($destination === null || $destination->domain === null || ! self::mayPush($destination, $settings)) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        $until = $now->subSeconds(self::INGEST_MARGIN_SECONDS);
        $batchRows = $settings->integer('log_push', 'batch_rows');
        $batches = $settings->integer('log_push', 'max_batches_per_run');
        $floor = $now->subDays($settings->integer('telemetry', 'raw_retention_days'));
        $cursors = $destination->cursors ?: [];
        $startedAt = $destination->created_at ?? $now;
        try {
            foreach ($destination->datasets as $dataset) {
                $cursor = $cursors[$dataset] ?? ['at' => $startedAt->format('Y-m-d H:i:s.v'), 'id' => self::ZERO_ID];
                if (CarbonImmutable::parse($cursor['at'], 'UTC')->lt($floor)) {
                    // The destination fell behind the retention window: skip ahead and record the gap.
                    $cursor = ['at' => $floor->format('Y-m-d H:i:s.v'), 'id' => self::ZERO_ID];
                    $cursors[$dataset] = $cursor;
                    $destination->forceFill(['cursors' => $cursors, 'last_gap_at' => $now])->save();
                }
                for ($batch = 0; $batch < $batches; $batch++) {
                    $rows = $store->after($dataset, $destination->domain_id, $cursor['at'], $cursor['id'], $until, $batchRows);
                    if ($rows === []) {
                        break;
                    }
                    $body = $this->encode($rows, $dataset, $destination->truncate_client_ip);
                    $sent = $sender->send($destination, $dataset, $body, (string) $rows[0]['event_id']);
                    $last = $rows[count($rows) - 1];
                    $cursor = ['at' => $last['occurred_at'], 'id' => $last['event_id']];
                    $cursors[$dataset] = $cursor;
                    $destination->forceFill([
                        'cursors' => $cursors, 'last_delivery_at' => $now, 'lag_seconds' => max(0, $now->diffInSeconds(CarbonImmutable::parse($last['occurred_at'], 'UTC'))),
                        'rows_pushed' => $destination->rows_pushed + count($rows), 'bytes_pushed' => $destination->bytes_pushed + $sent,
                        'consecutive_failures' => 0, 'last_error' => null, 'next_run_at' => null,
                    ])->save();
                    if (count($rows) < $batchRows) {
                        break;
                    }
                }
            }
        } catch (Throwable $exception) {
            $this->recordFailure($destination, $exception, $settings, $now);
        }
    }

    /** A destination pushes only while the administrator switch is on and it is active. */
    public static function mayPush(LogPushDestination $destination, PlatformSettings $settings): bool
    {
        return $destination->status === 'active' && $settings->get('log_push', 'domain_user_log_push') === true;
    }

    /** @param list<array<string, mixed>> $rows */
    private function encode(array $rows, string $dataset, bool $truncate): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $out = ['dataset' => $dataset, 'event_id' => $row['event_id'], 'occurred_at' => $row['occurred_at']];
            foreach (LogDatasets::fields($dataset) as $field) {
                $value = $row[$field] ?? null;
                $out[$field] = $truncate && $field === 'client_ip' ? LogDatasets::truncateAddress((string) $value) : $value;
            }
            $lines[] = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return (string) gzencode(implode("\n", $lines)."\n", 6);
    }

    private function recordFailure(LogPushDestination $destination, Throwable $exception, PlatformSettings $settings, CarbonImmutable $now): void
    {
        $failures = $destination->consecutive_failures + 1;
        $destination->forceFill([
            'consecutive_failures' => $failures, 'failures_total' => $destination->failures_total + 1, 'last_error' => mb_substr($exception->getMessage(), 0, 500),
            // Backoff doubles from one minute up to thirty, and the cursor stays where the last accepted batch left it.
            'next_run_at' => $now->addSeconds(min(1800, 60 * (2 ** min($failures - 1, 5)))),
        ])->save();
        if ($failures === $settings->integer('log_push', 'failure_notify_after')) {
            EventPublisher::publish('log_push.failing', $destination->domain, "Log push is failing for {$destination->domain->name}", "{$destination->name}: ".mb_substr($exception->getMessage(), 0, 200));
        }
    }
}
