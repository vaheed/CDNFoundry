<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Edge extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['bootstrap_token_hash', 'identity_hash'];

    public function cells(): HasMany
    {
        return $this->hasMany(EdgeCell::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(EdgeArtifact::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EdgeTask::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'drained' => 'boolean', 'capacity' => 'array', 'identity_revoked_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'last_heartbeat_at' => 'immutable_datetime'];
    }
}
