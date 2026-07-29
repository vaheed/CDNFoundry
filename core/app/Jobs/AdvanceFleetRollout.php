<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\EdgeTask;
use App\Models\FleetRollout;
use App\Models\FleetRolloutEdge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AdvanceFleetRollout implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $rolloutId)
    {
        $this->onQueue('runtime');
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $rollout = FleetRollout::query()->with(['release', 'previousRelease'])->lockForUpdate()->find($this->rolloutId);
            if ($rollout === null || in_array($rollout->status, ['paused', 'succeeded', 'failed', 'rolled_back', 'cancelled'], true)) {
                return;
            }
            $rows = $rollout->edges()->with('edge')->orderBy('wave')->orderBy('id')->get();
            if ($rollout->started_at !== null && $rows->contains(fn (FleetRolloutEdge $row): bool => $row->status !== 'succeeded')
                && $rollout->started_at->lt(now()->subMinutes($rollout->mixed_version_window_minutes))) {
                $rollout->update(['status' => 'paused', 'pause_reason' => 'mixed_version_window_exceeded']);
                AuditLog::record(null, 'fleet_rollout.paused', $rollout, ['reason' => 'mixed_version_window_exceeded']);

                return;
            }
            $failed = $rows->where('status', 'failed');
            if ($failed->isNotEmpty()) {
                $rollout->update(['status' => 'paused', 'pause_reason' => 'edge_upgrade_failed']);
                AuditLog::record(null, 'fleet_rollout.paused', $rollout, ['reason' => 'edge_upgrade_failed', 'failed_edges' => $failed->count()]);

                return;
            }
            if ($rows->isNotEmpty() && $rows->every(fn (FleetRolloutEdge $row): bool => $row->status === 'succeeded')) {
                $rolledBack = $rollout->status === 'rolling_back';
                $rollout->update(['status' => $rolledBack ? 'rolled_back' : 'succeeded', 'finished_at' => now(), 'pause_reason' => null]);
                AuditLog::record(null, $rolledBack ? 'fleet_rollout.rolled_back' : 'fleet_rollout.succeeded', $rollout);

                return;
            }
            $wave = (int) $rows->whereIn('status', ['pending', 'dispatched'])->min('wave');
            if ($wave > 0 && $rows->where('wave', '<', $wave)->contains(fn (FleetRolloutEdge $row): bool => $row->status !== 'succeeded')) {
                return;
            }
            $active = $rows->where('status', 'dispatched')->count();
            $available = max(0, $rollout->maximum_parallel - $active);
            $rows->where('wave', $wave)->where('status', 'pending')->take($available)->each(function (FleetRolloutEdge $row) use ($rollout): void {
                $edge = $row->edge;
                $fresh = $edge->last_heartbeat_at?->gte(now()->subSeconds(30))
                    && ($edge->capacity['listener_ready'] ?? false)
                    && ! $edge->drained;
                if (! $fresh) {
                    $rollout->update(['status' => 'paused', 'pause_reason' => 'edge_not_ready']);
                    AuditLog::record(null, 'fleet_rollout.paused', $rollout, ['reason' => 'edge_not_ready', 'edge_id' => $edge->id]);

                    return;
                }
                $task = EdgeTask::query()->create([
                    'edge_id' => $edge->id, 'type' => 'runtime_upgrade', 'status' => 'pending',
                    'payload' => ['rollout_id' => $rollout->id, 'rollout_edge_id' => $row->id, 'versions' => $row->desired_versions],
                ]);
                $edge->update(['desired_runtime_versions' => $row->desired_versions]);
                $row->update(['status' => 'dispatched', 'started_at' => now()]);
                AuditLog::record(null, 'fleet_rollout.edge_dispatched', $rollout, ['edge_id' => $edge->id, 'wave' => $row->wave, 'task_id' => $task->id]);
            });
            if ($rollout->status === 'pending') {
                $rollout->update(['status' => 'running', 'current_wave' => $wave, 'started_at' => now()]);
            } elseif (in_array($rollout->status, ['running', 'rolling_back'], true)) {
                $rollout->update(['current_wave' => $wave]);
            }
        });
    }
}
