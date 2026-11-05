<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'location', 'enabled', 'api_url', 'api_key', 'server_id', 'nameservers', 'capacity_zones', 'operational_notes', 'last_health_status', 'last_health_error', 'last_health_at', 'last_reconciled_revision', 'transfer_endpoint', 'transfer_tsig_secret'])]
class DnsCluster extends Model
{
    protected static function booted(): void
    {
        // The signing secret for zone transfers is generated, never typed, and is shown to nobody.
        static::creating(function (DnsCluster $cluster): void {
            $cluster->transfer_tsig_secret ??= base64_encode(random_bytes(32));
        });
    }

    /** The transfer secret, generated on first use for a cluster created before transfers existed. */
    public function transferSecret(): string
    {
        if (blank($this->transfer_tsig_secret)) {
            $this->forceFill(['transfer_tsig_secret' => base64_encode(random_bytes(32))])->save();
        }

        return $this->transfer_tsig_secret;
    }

    public function apiTarget(): string
    {
        $parts = parse_url($this->api_url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        return $host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(DnsDeployment::class);
    }

    protected $hidden = ['api_key', 'transfer_tsig_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'api_key' => 'encrypted', 'transfer_tsig_secret' => 'encrypted', 'nameservers' => 'array',
            'last_health_at' => 'immutable_datetime',
        ];
    }
}
