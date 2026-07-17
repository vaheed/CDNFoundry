<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdgeRevision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
