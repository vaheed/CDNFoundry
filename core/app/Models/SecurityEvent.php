<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
