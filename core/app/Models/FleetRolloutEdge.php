<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetRolloutEdge extends Model
{
    protected $guarded = [];

    public function rollout(): BelongsTo
    {
        return $this->belongsTo(FleetRollout::class, 'fleet_rollout_id');
    }

    public function edge(): BelongsTo
    {
        return $this->belongsTo(Edge::class);
    }

    protected function casts(): array
    {
        return ['previous_versions' => 'array', 'desired_versions' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
