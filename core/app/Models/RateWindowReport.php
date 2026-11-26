<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateWindowReport extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['reported_at' => 'datetime'];
    }
}
