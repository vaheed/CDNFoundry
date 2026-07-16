<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain_id', 'dns_cluster_id', 'desired_revision', 'deployed_revision', 'status', 'active_checksum', 'active_rrsets', 'last_error', 'attempts', 'last_attempted_at', 'deployed_at'])]
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

    protected function casts(): array
    {
        return ['active_rrsets' => 'array', 'last_attempted_at' => 'immutable_datetime', 'deployed_at' => 'immutable_datetime'];
    }
}
