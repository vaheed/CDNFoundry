<?php

namespace App\Models;

use App\Enums\DomainLifecycleState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'display_name', 'lifecycle_state', 'revision', 'nameservers_verified_at', 'nameservers_verified_by', 'disabled_at', 'deprovision_after', 'proxy_settings', 'active_edge_revision'])]
class Domain extends Model
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('created_at');
    }

    public function dnsRecords(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function dnsDeployments(): HasMany
    {
        return $this->hasMany(DnsDeployment::class);
    }

    public function edgePlacement(): HasOne
    {
        return $this->hasOne(DomainEdgePlacement::class);
    }

    protected function casts(): array
    {
        return [
            'lifecycle_state' => DomainLifecycleState::class,
            'nameservers_verified_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'deprovision_after' => 'immutable_datetime',
            'proxy_settings' => 'array',
            'active_edge_revision' => 'integer',
        ];
    }
}
