<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/** Where one domain's logs are pushed. The cursor, counters, and last error are the only state that changes while it runs. */
class LogPushDestination extends Model
{
    use HasUuids;

    public const KINDS = ['https', 's3'];

    protected $guarded = [];

    protected $hidden = ['secret_ciphertext'];

    protected function casts(): array
    {
        return [
            'datasets' => 'array', 'cursors' => 'array', 'truncate_client_ip' => 'boolean',
            'next_run_at' => 'immutable_datetime', 'last_delivery_at' => 'immutable_datetime', 'last_gap_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer', 'rows_pushed' => 'integer', 'bytes_pushed' => 'integer', 'lag_seconds' => 'integer',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return array<string, string> */
    public function secrets(): array
    {
        return json_decode(Crypt::decryptString($this->secret_ciphertext), true, 8, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $secrets */
    public function storeSecrets(array $secrets): void
    {
        $this->secret_ciphertext = Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR));
    }
}
