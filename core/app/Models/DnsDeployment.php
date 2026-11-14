<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain_id', 'dns_cluster_id', 'desired_revision', 'deployed_revision', 'status', 'active_checksum', 'active_rrsets', 'last_error', 'attempts', 'last_attempted_at', 'deployed_at', 'verified_at', 'tombstone', 'deprovisioned_at'])]
class DnsDeployment extends Model
{
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(DnsCluster::class, 'dns_cluster_id');
    }

    /**
     * Deployments that are behind or broken. A deleted domain keeps a pending tombstone until its retirement date, which
     * is the plan and not drift; only a failed retirement counts.
     */
    public function scopeDrifted(Builder $query): Builder
    {
        return $query->where(fn (Builder $drifted) => $drifted->where('status', 'failed')->orWhere(fn (Builder $pending) => $pending->where('status', 'pending')->where('tombstone', false)));
    }

    protected function casts(): array
    {
        return ['active_rrsets' => 'array', 'tombstone' => 'boolean', 'last_attempted_at' => 'immutable_datetime', 'deployed_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'deprovisioned_at' => 'immutable_datetime'];
    }
}
