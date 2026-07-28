<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdgePool extends Model
{
    protected $guarded = [];

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
        return ['enabled' => 'boolean', 'withdrawn' => 'boolean', 'minimum_ready_cells' => 'integer', 'replicas_per_edge' => 'integer', 'maximum_domains_per_cell' => 'integer'];
    }
}
