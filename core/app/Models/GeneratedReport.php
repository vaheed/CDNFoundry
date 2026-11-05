<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GeneratedReport extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'size_bytes' => 'integer'];
    }
}
