<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainEdgePlacement extends Model
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

    public function activePool(): BelongsTo
    {
        return $this->belongsTo(EdgePool::class, 'active_pool_id');
    }

    public function targetPool(): BelongsTo
    {
        return $this->belongsTo(EdgePool::class, 'target_pool_id');
    }
}
