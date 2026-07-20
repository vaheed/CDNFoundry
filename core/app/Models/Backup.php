<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime'];
    }
}
