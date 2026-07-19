<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TlsOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['private_key_ciphertext', 'csr_der'];

    public function challenges(): HasMany
    {
        return $this->hasMany(AcmeChallenge::class);
    }

    protected function casts(): array
    {
        return [
            'names' => 'array', 'authorization_urls' => 'array',
            'private_key_ciphertext' => 'encrypted',
            'available_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'next_poll_at' => 'immutable_datetime',
            'alerted_at' => 'immutable_datetime',
        ];
    }
}
