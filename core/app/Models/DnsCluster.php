<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'location', 'enabled', 'api_url', 'api_key', 'server_id', 'nameservers', 'capacity_zones', 'operational_notes', 'last_health_status', 'last_health_error', 'last_health_at', 'last_reconciled_revision'])]
class DnsCluster extends Model
{
    public function deployments(): HasMany
    {
        return $this->hasMany(DnsDeployment::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'api_key' => 'encrypted', 'nameservers' => 'array',
            'last_health_at' => 'immutable_datetime',
        ];
    }
}
