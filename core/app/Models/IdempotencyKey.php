<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'key', 'request_hash', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyKey extends Model
{
    protected function casts(): array
    {
        return ['response_body' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
