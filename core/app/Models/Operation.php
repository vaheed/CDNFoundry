<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Operation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function coalesceDomain(string $type, int $domainId, ?int $actorId = null): self
    {
        return self::query()->where('type', $type)->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $domainId)->first() ?? self::query()->create([
                'actor_id' => $actorId, 'type' => $type, 'status' => 'pending', 'input' => ['domain_id' => $domainId],
            ]);
    }
}
