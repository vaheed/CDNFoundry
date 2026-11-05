<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetRollout extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The rollout's operation mirrors its progress: paused and rolling back are still unfinished.
        static::saved(function (self $rollout): void {
            if ($rollout->operation_id === null || ! $rollout->wasChanged('status')) {
                return;
            }
            $status = match ($rollout->status) {
                'succeeded', 'rolled_back' => 'succeeded',
                'failed' => 'failed',
                default => 'running',
            };
            Operation::query()->whereKey($rollout->operation_id)->update([
                'status' => $status, 'error' => $status === 'failed' ? 'The rollout failed.' : null,
                'finished_at' => $status === 'running' ? null : now(),
            ]);
        });
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(FleetRelease::class, 'fleet_release_id');
    }

    public function previousRelease(): BelongsTo
    {
        return $this->belongsTo(FleetRelease::class, 'previous_release_id');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(FleetRolloutEdge::class);
    }

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
