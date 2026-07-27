<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeCell extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['drained' => 'boolean', 'capacity' => 'array', 'resource_limits' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (EdgeCell $cell): void {
            if ($cell->slot === null) {
                $cell->slot = ((int) static::query()->where('edge_id', $cell->edge_id)->max('slot')) + 1;
            }
            $cell->name = sprintf('cell-%02d', $cell->slot);
            $cell->http_port ??= 18080 + $cell->slot;
            $cell->https_port ??= 18443 + $cell->slot;
            $cell->status_port ??= 19080 + $cell->slot;
            $cell->runtime_path ??= "/var/lib/cdnfoundry/runtime/{$cell->name}.json";
            $cell->cache_path ??= "/var/cache/cdnfoundry/{$cell->name}";
            $cell->temporary_path ??= "/var/lib/cdnfoundry/tmp/{$cell->name}";
            $cell->resource_limits ??= ['memory_bytes' => 536870912, 'cpu_millis' => 500, 'pids' => 128, 'cache_bytes' => 268435456, 'temporary_bytes' => 67108864, 'log_bytes' => 16777216];
            $cell->status = $cell->status ?: ($cell->edge_pool_id === null ? 'unassigned' : 'assigned');
        });
    }

    public function edge(): BelongsTo
    {
        return $this->belongsTo(Edge::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(EdgePool::class, 'edge_pool_id');
    }
}
