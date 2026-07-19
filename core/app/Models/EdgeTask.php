<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EdgeTask extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'available_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
