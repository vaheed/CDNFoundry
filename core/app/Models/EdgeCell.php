<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeCell extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['drained' => 'boolean', 'capacity' => 'array'];
    }

    public function edge(): BelongsTo
    {
        return $this->belongsTo(Edge::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(EdgePool::class, 'edge_pool_id');
    }
}
