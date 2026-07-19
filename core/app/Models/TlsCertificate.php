<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TlsCertificate extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['private_key_ciphertext'];

    protected function casts(): array
    {
        return [
            'private_key_ciphertext' => 'encrypted', 'names' => 'array',
            'not_before' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime', 'last_failure_at' => 'immutable_datetime',
            'alerted_at' => 'immutable_datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
