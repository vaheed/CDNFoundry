<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdgePool extends Model
{
    protected $guarded = [];

    // Retained only for mixed-version database compatibility during contract.
    protected $hidden = ['waf_canary_state'];

    public function cells(): HasMany
    {
        return $this->hasMany(EdgeCell::class);
    }

    public function endpoints(): HasMany
    {
        return $this->hasMany(EdgePoolEndpoint::class);
    }

    public function isReady(): bool
    {
        $cells = $this->cells()->whereHas('edge', fn ($query) => $query->where('enabled', true)->where('drained', false))
            ->get(['edge_id', 'status', 'drained']);

        return $cells->isNotEmpty() && $cells->groupBy('edge_id')->every(fn ($edgeCells): bool => $edgeCells->where('drained', false)->where('status', 'ready')->count() >= $this->minimum_ready_cells
        );
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'withdrawn' => 'boolean', 'waf_capable' => 'boolean', 'minimum_ready_cells' => 'integer', 'replicas_per_edge' => 'integer', 'maximum_domains_per_cell' => 'integer'];
    }

    public function isSimpleAnycast(): bool
    {
        return $this->routing_mode === 'simple_anycast';
    }

    public function routingStatus(): string
    {
        if ($this->withdrawn) {
            return 'withdrawn';
        }
        if (! $this->enabled) {
            return 'disabled';
        }
        $endpoints = $this->endpoints()->with(['edge', 'pool'])->get();
        if ($endpoints->isEmpty() || $endpoints->every(fn (EdgePoolEndpoint $endpoint): bool => ! $endpoint->isReady())) {
            return 'unavailable';
        }

        return $endpoints->every(fn (EdgePoolEndpoint $endpoint): bool => $endpoint->isReady()) ? 'ready' : 'degraded';
    }
}
