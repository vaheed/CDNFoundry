<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReconcileAllTls implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        $count = (int) ($operation->result['domains_dispatched'] ?? 0);
        $ids = Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)->where('tls_mode', 'managed')->where('id', '>', $cursor)->orderBy('id')->limit(250)->pluck('id');
        $operation->update(['status' => 'running', 'started_at' => $operation->started_at ?? now(), 'attempts' => $operation->attempts + 1]);
        foreach ($ids as $id) {
            EnsureManagedCertificates::dispatch((int) $id);
        }
        $count += $ids->count();
        $cursor = (int) ($ids->last() ?? $cursor);
        $more = $ids->count() === 250 && Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)->where('tls_mode', 'managed')->where('id', '>', $cursor)->exists();
        $operation->update(['status' => $more ? 'running' : 'succeeded', 'result' => ['cursor' => $cursor, 'domains_dispatched' => $count], 'finished_at' => $more ? null : now()]);
        if ($more) {
            self::dispatch($operation->id)->delay(now()->addSecond());
        }
    }

    public function failed(Throwable $exception): void
    {
        Operation::query()->whereKey($this->operationId)->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
    }
}
