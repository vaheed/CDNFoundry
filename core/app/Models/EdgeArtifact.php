<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdgeArtifact extends Model
{
    protected $primaryKey = 'sequence';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
