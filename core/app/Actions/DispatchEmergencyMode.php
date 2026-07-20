<?php

namespace App\Actions;

use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgeTask;
use App\Models\EmergencyMode;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchEmergencyMode
{
    public static function activate(string $targetType, string $targetId, array $actions, ?int $durationMinutes, User $actor): array
    {
        return DB::transaction(function () use ($actions, $actor, $durationMinutes, $targetId, $targetType): array {
            abort_if(EmergencyMode::query()->where('target_type', $targetType)->where('target_id', $targetId)->where('active', true)->exists(), 409, 'This target already has an active emergency mode. Clear it before applying another.');
            $mode = EmergencyMode::query()->create([
                'target_type' => $targetType, 'target_id' => $targetId, 'actions' => array_values($actions),
                'expires_at' => $durationMinutes === null ? null : now()->addMinutes($durationMinutes),
                'created_by' => $actor->id,
            ]);
            $operation = Operation::query()->create([
                'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'type' => 'edge.emergency_mode', 'status' => 'pending',
                'input' => ['emergency_mode_id' => $mode->id, 'target_type' => $targetType, 'target_id' => $targetId, 'active' => true],
            ]);
            self::tasks($mode, $operation, true);

            return [$mode, $operation];
        });
    }

    public static function deactivateTarget(string $targetType, string $targetId, User|string|null $actor): Operation
    {
        return DB::transaction(function () use ($actor, $targetId, $targetType): Operation {
            $modes = EmergencyMode::query()->where('target_type', $targetType)->where('target_id', $targetId)->where('active', true)->lockForUpdate()->get();
            abort_if($modes->isEmpty(), 404, 'No active emergency mode exists for this target.');
            $modes->each->update(['active' => false, 'deactivated_at' => now()]);
            $actorId = $actor instanceof User ? $actor->id : null;
            $operation = Operation::query()->create([
                'id' => (string) Str::uuid(), 'actor_id' => $actorId, 'type' => 'edge.emergency_mode', 'status' => 'pending',
                'input' => ['emergency_mode_ids' => $modes->pluck('id')->all(), 'target_type' => $targetType, 'target_id' => $targetId, 'active' => false],
            ]);
            foreach ($modes as $mode) {
                self::tasks($mode, $operation, false);
            }

            return $operation;
        });
    }

    private static function tasks(EmergencyMode $mode, Operation $operation, bool $active): void
    {
        $edges = self::edges($mode->target_type, $mode->target_id);
        abort_if($edges->isEmpty(), 409, 'The emergency target has no participating edge.');
        foreach ($edges as $edge) {
            $cellNames = match ($mode->target_type) {
                'cell' => [EdgeCell::query()->findOrFail((int) $mode->target_id)->name],
                'pool' => $edge->cells()->where('edge_pool_id', (int) $mode->target_id)->orderBy('name')->limit(32)->pluck('name')->all(),
                default => [],
            };
            EdgeTask::query()->create([
                'id' => (string) Str::uuid(), 'edge_id' => $edge->id, 'type' => 'emergency_mode', 'status' => 'pending',
                'payload' => [
                    'operation_id' => $operation->id, 'emergency_mode_id' => $mode->id, 'active' => $active,
                    'target_type' => $mode->target_type, 'target_id' => $mode->target_id,
                    'cell_names' => $cellNames,
                    'actions' => $mode->actions, 'expires_at' => $active ? $mode->expires_at?->timestamp : null,
                ],
            ]);
        }
        $operation->update(['status' => 'running', 'started_at' => now(), 'result' => ['tasks' => $edges->count(), 'completed' => 0]]);
    }

    private static function edges(string $targetType, string $targetId): Collection
    {
        return match ($targetType) {
            'edge' => Edge::query()->whereKey($targetId)->where('enabled', true)->get(),
            'cell' => Edge::query()->whereHas('cells', fn ($query) => $query->whereKey((int) $targetId))->where('enabled', true)->get(),
            'pool' => Edge::query()->whereHas('cells', fn ($query) => $query->where('edge_pool_id', (int) $targetId))->where('enabled', true)->get(),
            default => collect(),
        };
    }
}
