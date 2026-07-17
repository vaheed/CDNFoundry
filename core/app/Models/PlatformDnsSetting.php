<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDnsSetting extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected function casts(): array
    {
        return ['nameservers' => 'array', 'cluster_targets' => 'array', 'revision' => 'integer'];
    }
}
