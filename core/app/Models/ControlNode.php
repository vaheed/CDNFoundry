<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ControlNode extends Model
{
    protected $fillable = ['name', 'role', 'address', 'fingerprints', 'replication_lag_seconds', 'valkey_linked', 'reported_at'];

    protected function casts(): array
    {
        return ['fingerprints' => 'array', 'valkey_linked' => 'boolean', 'reported_at' => 'datetime'];
    }
}
