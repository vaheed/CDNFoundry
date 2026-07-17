<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain_id', 'type', 'name', 'content', 'content_hash', 'ttl', 'priority', 'weight', 'port', 'mode', 'geo_config'])]
class DnsRecord extends Model
{
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    protected function casts(): array
    {
        return ['geo_config' => 'array'];
    }
}
