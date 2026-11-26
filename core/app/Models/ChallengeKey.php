<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key_id
 * @property string $secret hex, 32 random bytes; encrypted at rest
 * @property string $state active|previous|retired
 * @property int $revision
 * @property Carbon $activated_at
 * @property ?Carbon $retire_at
 * @property ?Carbon $retired_at
 * @property ?string $retired_reason
 */
class ChallengeKey extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret_ciphertext'];

    protected function casts(): array
    {
        return ['secret_ciphertext' => 'encrypted', 'activated_at' => 'datetime', 'retire_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function getSecretAttribute(): string
    {
        return $this->secret_ciphertext;
    }
}
