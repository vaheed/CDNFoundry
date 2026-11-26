<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What one cell reported about one client of an enrolled domain; derived, replaced by the cell's next report. */
class ClientReputationReport extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['reported_at' => 'datetime'];
    }
}
