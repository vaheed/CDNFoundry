<?php

namespace App\Models;

use App\Enums\DomainLifecycleState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'display_name', 'lifecycle_state', 'revision', 'nameservers_verified_at', 'nameservers_verified_by', 'disabled_at', 'deprovision_after', 'proxy_settings', 'active_edge_revision', 'cache_settings', 'cache_epoch', 'cache_development_mode_until', 'tls_mode', 'active_tls_certificate_id', 'security_settings', 'security_state', 'security_state_changed_at'])]
class Domain extends Model
{
    use SoftDeletes;

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

    public function tlsCertificates(): HasMany
    {
        return $this->hasMany(TlsCertificate::class);
    }

    public function tlsOrders(): HasMany
    {
        return $this->hasMany(TlsOrder::class);
    }

    public function latestTlsOrder(): HasOne
    {
        return $this->hasOne(TlsOrder::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function securityRules(): HasMany
    {
        return $this->hasMany(SecurityRule::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    public function activeTlsCertificate(): BelongsTo
    {
        return $this->belongsTo(TlsCertificate::class, 'active_tls_certificate_id');
    }

    protected function casts(): array
    {
        return [
            'lifecycle_state' => DomainLifecycleState::class,
            'nameservers_verified_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'deprovision_after' => 'immutable_datetime',
            'proxy_settings' => 'array',
            'cache_settings' => 'array',
            'cache_development_mode_until' => 'immutable_datetime',
            'security_settings' => 'array',
            'security_state_changed_at' => 'immutable_datetime',
            'active_edge_revision' => 'integer',
            'revision_changed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Domain $domain): void {
            if (! $domain->exists || $domain->isDirty('revision')) {
                $domain->revision_changed_at = now();
            }
        });
    }
}
