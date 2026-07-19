<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcmeChallenge extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(TlsOrder::class, 'tls_order_id');
    }

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'cleaned_at' => 'immutable_datetime'];
    }
}
