<?php

namespace App\Models;

use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Edge extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['bootstrap_token_hash', 'identity_hash', 'identity_csr_hash', 'identity_certificate'];

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

    public function scopeReadyForTraffic(Builder $query): Builder
    {
        return $query->where('enabled', true)->where('drained', false)
            ->whereNull('identity_revoked_at')->whereNotNull('registered_at')
            ->where('last_heartbeat_at', '>=', now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')))
            ->where('capacity->listener_ready', true);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'drained' => 'boolean', 'capacity' => 'array', 'bootstrap_consumed_at' => 'immutable_datetime', 'identity_revoked_at' => 'immutable_datetime', 'identity_certificate_expires_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'last_heartbeat_at' => 'immutable_datetime'];
    }
}
