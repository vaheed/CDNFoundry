<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdgePool extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
