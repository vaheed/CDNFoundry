<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['dns_cluster_id', 'desired_revision', 'deployed_revision', 'status', 'active_checksum', 'active_zone', 'active_rrsets', 'last_error', 'attempts', 'last_attempted_at', 'deployed_at'])]
class PlatformDnsDeployment extends Model
{
    protected function casts(): array
    {
        return [
            'active_rrsets' => 'array',
            'last_attempted_at' => 'immutable_datetime',
            'deployed_at' => 'immutable_datetime',
        ];
    }
}
