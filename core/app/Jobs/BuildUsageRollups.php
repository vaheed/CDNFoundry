<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\Operation;
use App\Models\UsageRollup;
use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BuildUsageRollups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(public string $from, public string $to, public ?int $domainId = null, public ?string $operationId = null)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function handle(AnalyticsStore $store): void
    {
        $operation = $this->operationId ? Operation::query()->find($this->operationId) : null;
        $operation?->update(['status' => 'running', 'started_at' => now()]);
        try {
            $from = CarbonImmutable::parse($this->from)->utc();
            $to = CarbonImmutable::parse($this->to)->utc();
            for ($interval = $from; $interval < $to; $interval = $interval->addHour()) {
                $end = $interval->addHour();
                $totals = collect($store->usageInterval($interval, $end, $this->domainId))->groupBy('domain_id')->map(fn ($rows): array => [
                    'requests' => $rows->sum('requests'), 'bytes_in' => $rows->sum('bytes_in'), 'bytes_out' => $rows->sum('bytes_out'),
                    'cache_hits' => $rows->sum('cache_hits'), 'dns_queries' => $rows->sum('dns_queries'),
                ]);
                $domainIds = $this->domainId === null ? $totals->keys()->map(fn ($id): int => (int) $id)->all() : [$this->domainId];
                $existing = Domain::query()->whereIn('id', $domainIds)->pluck('id')->all();
                foreach ($existing as $domainId) {
                    $values = $totals->get((string) $domainId, $totals->get($domainId, ['requests' => 0, 'bytes_in' => 0, 'bytes_out' => 0, 'cache_hits' => 0, 'dns_queries' => 0]));
                    UsageRollup::query()->updateOrCreate(
                        ['domain_id' => $domainId, 'interval_start' => $interval, 'granularity' => 'hour'],
                        [...$values, 'interval_end' => $end, 'status' => 'finalized', 'source_finalized_at' => now()],
                    );
                }
            }
            $operation?->update(['status' => 'succeeded', 'finished_at' => now(), 'result' => ['from' => $this->from, 'to' => $this->to]]);
        } catch (Throwable $exception) {
            $operation?->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'analytics_unavailable: Usage source is unavailable.']);
            throw $exception;
        }
    }
}
