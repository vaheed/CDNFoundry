<?php

namespace App\Jobs;

use App\Models\DnsRecord;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class DispatchOriginTest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $operationId)
    {
        $this->onQueue('runtime');
    }

    public function handle(): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        if (! in_array($operation->status, ['pending', 'running'], true)) {
            return;
        }
        $record = DnsRecord::query()->find($operation->input['record_id']);
        if ($record === null || $record->mode !== 'proxied') {
            $operation->update(['status' => 'failed', 'error' => 'The proxied hostname no longer exists.', 'finished_at' => now()]);

            return;
        }
        $selected = collect($operation->input['edge_ids'] ?? []);
        $edges = Edge::query()->where('enabled', true)->where('drained', false)->whereNull('identity_revoked_at')
            ->whereNotNull('registered_at')->where('last_heartbeat_at', '>=', now()->subSeconds((int) config('edge.heartbeat_fresh_seconds')))
            ->when($selected->isNotEmpty(), fn ($query) => $query->whereIn('id', $selected))
            ->orderBy('id')->limit(20)->get();
        if ($edges->isEmpty()) {
            $operation->update(['status' => 'failed', 'error' => 'No selected edge is registered, enabled, and heartbeat-fresh.', 'finished_at' => now()]);

            return;
        }
        foreach ($edges as $edge) {
            $exists = EdgeTask::query()->where('edge_id', $edge->id)->where('type', 'origin_test')
                ->where('payload->operation_id', $operation->id)->exists();
            if (! $exists) {
                EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'type' => 'origin_test', 'status' => 'pending', 'payload' => [
                    'operation_id' => $operation->id, 'domain_id' => $record->domain_id, 'record_id' => $record->id,
                    'origin' => $record->origin, 'addresses' => $operation->input['addresses'],
                    'private_allowlist' => config('edge.private_origin_allowlist', []),
                ]]);
            }
        }
        $operation->update(['status' => 'running', 'started_at' => $operation->started_at ?? now(), 'result' => ['tasks' => $edges->count(), 'completed' => 0]]);
    }
}
