<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $observer_edge_id
 * @property string $target_edge_id
 * @property string $address
 * @property bool $ok
 * @property CarbonImmutable $checked_at
 */
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
