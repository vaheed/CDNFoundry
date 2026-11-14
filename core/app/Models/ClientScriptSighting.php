<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One script or connection a browser reported on one page of a domain. Rebuildable from telemetry apart from its approval. */
#[Fillable(['domain_id', 'kind', 'page_path', 'resource', 'first_seen_at', 'last_seen_at', 'approved_at', 'source'])]
class ClientScriptSighting extends Model
{
    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return array{id: int, kind: string, page: string, resource: string, first_seen_at: string, last_seen_at: string, status: string, approved_at: ?string} */
    public function toListing(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'page' => $this->page_path, 'resource' => $this->resource,
            'first_seen_at' => $this->first_seen_at->toIso8601String(), 'last_seen_at' => $this->last_seen_at->toIso8601String(),
            'status' => $this->approved_at === null ? 'new' : 'approved', 'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
