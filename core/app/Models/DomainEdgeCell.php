<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainEdgeCell extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['drain_after' => 'immutable_datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function edge(): BelongsTo
    {
        return $this->belongsTo(Edge::class);
    }

    public function activeCell(): BelongsTo
    {
        return $this->belongsTo(EdgeCell::class, 'active_cell_id');
    }

    public function targetCell(): BelongsTo
    {
        return $this->belongsTo(EdgeCell::class, 'target_cell_id');
    }
}
