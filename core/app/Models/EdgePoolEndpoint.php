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
        return ['withdrawn' => 'boolean', 'revision' => 'integer', 'gateway_revision' => 'integer', 'gateway_acknowledged_at' => 'immutable_datetime'];
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

    public function readinessReason(): string
    {
        if ($this->withdrawn || $this->pool->withdrawn || ! $this->pool->enabled) {
            return 'withdrawn';
        }
        if (! $this->edge->enabled || $this->edge->drained) {
            return 'edge_unavailable';
        }
        if (! $this->edge->last_heartbeat_at?->gte(now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')))) {
            return 'heartbeat_stale';
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
