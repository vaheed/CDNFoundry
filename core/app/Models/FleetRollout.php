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
