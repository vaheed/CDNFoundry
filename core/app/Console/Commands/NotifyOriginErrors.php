<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\Notifications\EventPublisher;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class NotifyOriginErrors extends Command
{
    protected $signature = 'cdnf:notifications:origin-errors';

    protected $description = 'Send one notification for each domain whose origin errors exceed the platform threshold, from the one-minute rollup';

    public function handle(AnalyticsStore $store, PlatformSettings $settings): int
    {
        $to = CarbonImmutable::now('UTC')->startOfMinute();
        try {
            $rows = $store->originErrorsByDomain($to->subMinutes(5), $to, $settings->integer('notifications', 'origin_error_min_requests'));
        } catch (Throwable $exception) {
            $this->warn('Telemetry is unavailable: '.$exception->getMessage());

            return self::SUCCESS;
        }
        $threshold = $settings->integer('notifications', 'origin_error_percent');
        foreach ($rows as $row) {
            $percent = (int) round(100 * (int) $row['origin_errors'] / max(1, (int) $row['requests']));
            $domain = Domain::query()->find((int) $row['domain_id']);
            // One notification per domain and hour, however long the origin keeps failing.
            if ($percent < $threshold || $domain === null || ! Cache::add("notify:origin-errors:{$domain->id}", 1, now()->addHour())) {
                continue;
            }
            EventPublisher::publish('origin.errors_high', $domain, "Origin errors for {$domain->name}", "{$percent}% of {$row['requests']} requests failed at the origin in the last five minutes.");
        }

        return self::SUCCESS;
    }
}
