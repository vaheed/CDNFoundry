<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CachePurge extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cache_keys' => 'array'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EdgeTask::class);
    }
}
