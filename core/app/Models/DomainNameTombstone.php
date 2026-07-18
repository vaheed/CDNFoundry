<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainNameTombstone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['deprovisioned_at' => 'immutable_datetime', 'reclaim_after' => 'immutable_datetime'];
    }
}
