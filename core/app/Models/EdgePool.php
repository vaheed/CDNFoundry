<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdgePool extends Model
{
    protected $guarded = [];

    public function cells(): HasMany
    {
        return $this->hasMany(EdgeCell::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'withdrawn' => 'boolean'];
    }
}
