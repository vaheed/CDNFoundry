<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain_id', 'interval_start', 'interval_end', 'granularity', 'requests', 'bytes_in', 'bytes_out', 'cache_hits', 'dns_queries', 'status', 'source_finalized_at'])]
class UsageRollup extends Model
{
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    protected function casts(): array
    {
        return ['interval_start' => 'immutable_datetime', 'interval_end' => 'immutable_datetime', 'source_finalized_at' => 'immutable_datetime'];
    }
}
