<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeProbeResult extends Model
{
    public function observer(): BelongsTo
    {
        return $this->belongsTo(Edge::class, 'observer_edge_id');
    }

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'checked_at' => 'immutable_datetime'];
    }
}
