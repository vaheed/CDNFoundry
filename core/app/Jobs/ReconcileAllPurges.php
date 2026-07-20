<?php

namespace App\Jobs;

use App\Models\CachePurge;
use App\Models\EdgeTask;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileAllPurges implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        $cursor = (string) ($operation->result['cursor'] ?? '');
        $count = (int) ($operation->result['purges_requeued'] ?? 0);
        $purges = CachePurge::query()->whereIn('status', ['pending', 'running', 'failed'])->when($cursor !== '', fn ($query) => $query->where('id', '>', $cursor))->orderBy('id')->limit(250)->get();
        $operation->update(['status' => 'running', 'started_at' => $operation->started_at ?? now(), 'attempts' => $operation->attempts + 1]);
        foreach ($purges as $purge) {
            DB::transaction(function () use ($purge): void {
                EdgeTask::query()->where('cache_purge_id', $purge->id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null, 'available_at' => now()]);
                $purge->update(['status' => $purge->tasks()->where('status', 'failed')->exists() ? 'failed' : 'running']);
            });
            $count++;
        }
        $cursor = (string) ($purges->last()?->id ?? $cursor);
        $more = $purges->count() === 250 && CachePurge::query()->whereIn('status', ['pending', 'running', 'failed'])->where('id', '>', $cursor)->exists();
        $operation->update(['status' => $more ? 'running' : 'succeeded', 'result' => ['cursor' => $cursor, 'purges_requeued' => $count], 'finished_at' => $more ? null : now()]);
        if ($more) {
            self::dispatch($operation->id)->delay(now()->addSecond());
        }
    }

    public function failed(Throwable $exception): void
    {
        Operation::query()->whereKey($this->operationId)->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
    }
}
