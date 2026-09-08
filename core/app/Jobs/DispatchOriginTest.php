<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Support\ArtifactSigner;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        DB::transaction(function (): void {
            $this->dispatchTasks(Operation::query()->lockForUpdate()->findOrFail($this->operationId));
        }, 3);
    }

    private function dispatchTasks(Operation $operation): void
    {
        if (! in_array($operation->status, ['pending', 'running'], true)) {
            return;
        }
        $domain = Domain::query()->lockForUpdate()->find($operation->input['domain_id'] ?? null);
        $actor = $operation->actor;
        $authorized = $actor !== null
            ? (! $actor->isDisabled() && $domain !== null && Gate::forUser($actor)->allows('update', $domain))
            : ($operation->input['scheduled'] ?? false) === true;
        if (! $authorized || $domain === null || $domain->lifecycle_state !== DomainLifecycleState::Active
            || $domain->nameservers_verified_at === null || $domain->disabled_at !== null) {
            $operation->update(['status' => 'failed', 'error' => 'Origin test authorization or active domain state changed; request a new test.', 'finished_at' => now()]);

            return;
        }
        $record = DnsRecord::query()->where('domain_id', $domain->id)->lockForUpdate()->find($operation->input['record_id']);
        $role = $operation->input['origin_role'] ?? 'primary';
        $origin = $role === 'backup' ? ($record?->origin['backup'] ?? null) : $record?->origin;
        $checksum = $operation->input['origin_checksum'] ?? null;
        if ($record === null || $record->mode !== 'proxied' || ! is_array($origin) || ! is_string($checksum)
            || ! hash_equals($checksum, hash('sha256', ArtifactSigner::encode($origin)))) {
            $operation->update(['status' => 'failed', 'error' => 'The origin changed or this test predates configuration binding; request a new test.', 'finished_at' => now()]);

            return;
        }
        $tasks = EdgeTask::query()->where('type', 'origin_test')->where('payload->operation_id', $operation->id)->get();
        // A committed dispatch fixes its recipients. Retries cannot expand
        // this operation as edge availability changes.
        if ($tasks->isEmpty()) {
            $selected = collect($operation->input['edge_ids'] ?? []);
            $edges = Edge::query()->where('enabled', true)->where('drained', false)->whereNull('identity_revoked_at')
                ->whereNotNull('registered_at')->where('last_heartbeat_at', '>=', now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')))
                ->when($selected->isNotEmpty(), fn ($query) => $query->whereIn('id', $selected))
                ->orderBy('id')->limit(20)->get();
            if ($edges->isEmpty()) {
                $operation->update(['status' => 'failed', 'error' => 'No selected edge is registered, enabled, and heartbeat-fresh.', 'finished_at' => now()]);

                return;
            }
            foreach ($edges as $edge) {
                EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'type' => 'origin_test', 'status' => 'pending', 'payload' => [
                    'operation_id' => $operation->id, 'domain_id' => $record->domain_id, 'record_id' => $record->id,
                    'origin_role' => $role, 'origin' => $origin, 'addresses' => $operation->input['addresses'],
                    'private_allowlist' => app(PlatformSettings::class)->get('origin_safety', 'private_origin_allowlist'),
                    'blocked_networks' => app(PlatformSettings::class)->get('origin_safety', 'blocked_origin_networks'),
                ]]);
            }
        }
        $tasks = EdgeTask::query()->where('type', 'origin_test')->where('payload->operation_id', $operation->id)->get();
        $completed = $tasks->whereIn('status', ['succeeded', 'failed']);
        $terminal = $tasks->isNotEmpty() && $completed->count() === $tasks->count();
        $operation->update([
            'status' => $terminal ? ($tasks->contains('status', 'failed') ? 'failed' : 'succeeded') : 'running',
            'started_at' => $operation->started_at ?? now(),
            'result' => ['tasks' => $tasks->count(), 'completed' => $completed->count(), 'edges' => $completed->map(fn (EdgeTask $task) => $task->result)->values()->all()],
            'finished_at' => $terminal ? now() : null,
        ]);
    }
}
