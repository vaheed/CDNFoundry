<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CachePurge extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The purge's operation mirrors its delivery state.
        static::saved(function (self $purge): void {
            if ($purge->operation_id === null || ! $purge->wasChanged('status') && ! $purge->wasRecentlyCreated) {
                return;
            }
            $finished = in_array($purge->status, ['succeeded', 'failed'], true);
            Operation::query()->whereKey($purge->operation_id)->update([
                'status' => $purge->status === 'pending' ? 'running' : $purge->status,
                'error' => $purge->status === 'failed' ? 'One or more edges did not apply the purge.' : null,
                'result' => json_encode(['cache_purge_id' => $purge->id, 'type' => $purge->type]),
                'finished_at' => $finished ? now() : null,
            ]);
        });
    }

    protected function casts(): array
    {
        return ['cache_keys' => 'array'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EdgeTask::class);
    }
}
