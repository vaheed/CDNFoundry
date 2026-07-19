<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmergencyMode extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['actions' => 'array', 'active' => 'boolean', 'expires_at' => 'immutable_datetime', 'deactivated_at' => 'immutable_datetime'];
    }
}
