<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A client key of a long-window rate rule that the control plane found over its threshold across cells.
 *
 * @property CarbonInterface $expires_at
 * @property int $revision
 */
class RateMitigation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
