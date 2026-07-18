<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReconcileAllEdgeDomains implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public string $operationId)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function uniqueId(): string
    {
        return $this->operationId;
    }

    public function handle(): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        if (! in_array($operation->status, ['pending', 'running'], true)) {
            return;
        }
        $cursor = (int) ($operation->result['cursor'] ?? 0);
        $dispatched = (int) ($operation->result['domains_dispatched'] ?? 0);
        $ids = Domain::query()->where('id', '>', $cursor)
            ->whereHas('dnsRecords', fn ($query) => $query->where('mode', 'proxied'))
            ->orderBy('id')->limit(500)->pluck('id');
        $operation->update(['status' => 'running', 'started_at' => $operation->started_at ?? now(), 'attempts' => $operation->attempts + 1]);
        foreach ($ids as $domainId) {
            ReconcileEdgeDomain::dispatch((int) $domainId);
        }
        $dispatched += $ids->count();
        $cursor = (int) ($ids->last() ?? $cursor);
        if ($ids->count() === 500) {
            $operation->update(['result' => ['cursor' => $cursor, 'domains_dispatched' => $dispatched]]);
            self::dispatch($operation->id)->delay(now()->addSecond());

            return;
        }
        $operation->update([
            'status' => 'succeeded', 'result' => ['cursor' => $cursor, 'domains_dispatched' => $dispatched],
            'error' => null, 'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Operation::query()->whereKey($this->operationId)->update([
            'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now(),
        ]);
    }
}
