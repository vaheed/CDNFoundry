<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileAllDnsZones implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 300;

    public function __construct(public string $operationId)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function handle(): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        if ($operation->status === 'succeeded') {
            return;
        }
        $cursor = (int) ($operation->result['cursor'] ?? 0);
        $dispatched = (int) ($operation->result['domains_dispatched'] ?? 0);
        $ids = Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)
            ->where('id', '>', $cursor)->orderBy('id')->limit(500)->pluck('id');
        $operation->update(['status' => 'running', 'started_at' => $operation->started_at ?? now(), 'attempts' => $operation->attempts + 1]);
        foreach ($ids as $id) {
            ReconcileDnsZone::dispatch($id);
            $dispatched++;
            $cursor = $id;
        }
        $hasMore = $ids->count() === 500 && Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)->where('id', '>', $cursor)->exists();
        $operation->update([
            'status' => $hasMore ? 'running' : 'succeeded',
            'result' => ['cursor' => $cursor, 'domains_dispatched' => $dispatched],
            'error' => null, 'finished_at' => $hasMore ? null : now(),
        ]);
        if ($hasMore) {
            self::dispatch($operation->id);
        }
    }

    public function uniqueId(): string
    {
        return $this->operationId;
    }
}
