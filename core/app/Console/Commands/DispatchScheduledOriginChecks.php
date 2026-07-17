<?php

namespace App\Console\Commands;

use App\Jobs\DispatchOriginTest;
use App\Models\DnsRecord;
use App\Models\Operation;
use App\Support\OriginData;
use Illuminate\Console\Command;

class DispatchScheduledOriginChecks extends Command
{
    protected $signature = 'edge:dispatch-origin-checks {--limit=100}';

    protected $description = 'Dispatch a bounded, jittered batch of explicitly enabled origin checks';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dispatched = 0;
        $perDomain = [];
        DnsRecord::query()->where('mode', 'proxied')->where('origin->health_check->enabled', true)
            ->orderBy('id')->limit($limit * 5)->get()->each(function (DnsRecord $record) use ($limit, &$dispatched, &$perDomain): void {
                if ($dispatched >= $limit || ($perDomain[$record->domain_id] ?? 0) >= 5 || ! $this->due($record)) {
                    return;
                }
                try {
                    $addresses = OriginData::resolveAndValidate($record->origin['host']);
                } catch (\Throwable) {
                    return;
                }
                $operation = Operation::query()->create(['type' => 'edge.origin_test', 'status' => 'pending', 'input' => [
                    'domain_id' => $record->domain_id, 'record_id' => $record->id, 'addresses' => $addresses, 'edge_ids' => [], 'scheduled' => true,
                ]]);
                DispatchOriginTest::dispatch($operation->id);
                $perDomain[$record->domain_id] = ($perDomain[$record->domain_id] ?? 0) + 1;
                $dispatched++;
            });
        $this->info("Dispatched $dispatched scheduled origin check(s).");

        return self::SUCCESS;
    }

    private function due(DnsRecord $record): bool
    {
        $interval = (int) ($record->origin['health_check']['interval_seconds'] ?? 0);
        if ($interval < 60) {
            return false;
        }
        $jitter = abs(crc32((string) $record->id)) % max(1, min(300, intdiv($interval, 10)));
        $last = isset($record->origin_health['reported_at']) ? strtotime($record->origin_health['reported_at']) : $record->created_at->getTimestamp();

        return time() >= $last + $interval + $jitter;
    }
}
