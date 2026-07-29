<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WafExclusion extends Model
{
    protected $guarded = [];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    protected function casts(): array
    {
        return ['rule_id' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
