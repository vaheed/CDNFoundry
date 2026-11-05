<?php

namespace App\Models;

use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgePoolEndpoint extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['withdrawn' => 'boolean', 'dns_published' => 'boolean', 'revision' => 'integer', 'gateway_revision' => 'integer', 'gateway_acknowledged_at' => 'immutable_datetime'];
    }

    public function edge(): BelongsTo
    {
        return $this->belongsTo(Edge::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(EdgePool::class, 'edge_pool_id');
    }

    public function readyCells()
    {
        return EdgeCell::query()->where('edge_id', $this->edge_id)->where('edge_pool_id', $this->edge_pool_id)
            ->where('drained', false)->where('status', 'ready');
    }

    /**
     * Why this endpoint should leave DNS, from positive signals only: an
     * operator action, a failed quorum of external probes, or the PoP's own
     * report that it cannot serve. A silent PoP is never a reason; stale
     * heartbeats only surface through monitoring.
     */
    public function publicationReason(): string
    {
        return $this->readinessReason(ignoreHeartbeat: true);
    }

    public function heartbeatIsStale(): bool
    {
        return ! $this->edge->last_heartbeat_at?->gte(now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')));
    }

    /** True when a quorum of other PoPs reports this endpoint's listeners unreachable. */
    public function failsExternalProbes(): bool
    {
        $settings = app(PlatformSettings::class);
        $minimum = $settings->integer('edge_runtime', 'probe_min_vantage_points');
        $cutoff = now()->subSeconds($settings->integer('edge_runtime', 'probe_result_max_age_seconds'));
        foreach (array_filter([$this->ipv4, $this->ipv6]) as $address) {
            $results = EdgeProbeResult::query()->where('target_edge_id', $this->edge_id)->where('address', $address)
                ->where('checked_at', '>=', $cutoff)->whereHas('observer', fn ($query) => $query->where('enabled', true)->whereNull('identity_revoked_at'))
                ->get(['ok']);
            if ($results->count() >= $minimum && $results->where('ok', false)->count() * 2 > $results->count()) {
                return true;
            }
        }

        return false;
    }

    public function readinessReason(bool $ignoreHeartbeat = false): string
    {
        if ($this->withdrawn || $this->pool->withdrawn) {
            return 'withdrawn';
        }
        if (! $this->pool->enabled) {
            return 'pool_disabled';
        }
        if (! $this->edge->enabled || $this->edge->drained) {
            return 'edge_unavailable';
        }
        if (! $ignoreHeartbeat && $this->heartbeatIsStale()) {
            return 'heartbeat_stale';
        }
        if ($ignoreHeartbeat && $this->failsExternalProbes()) {
            return 'probe_failed';
        }
        if (! ($this->edge->capacity['listener_ready'] ?? false)) {
            return 'gateway_not_ready';
        }
        if ($this->readyCells()->count() < $this->pool->minimum_ready_cells) {
            return 'insufficient_ready_cells';
        }
        if ($this->gateway_state !== 'ready' || $this->gateway_revision < $this->revision) {
            return 'gateway_not_acknowledged';
        }

        return 'ready';
    }

    public function isReady(): bool
    {
        return $this->readinessReason() === 'ready';
    }

    public function effectiveAddress(string $family): ?string
    {
        $field = $family === 'ipv6' ? 'ipv6' : 'ipv4';

        return $this->pool->isSimpleAnycast() ? $this->pool->{'anycast_'.$field} : $this->{$field};
    }
}
