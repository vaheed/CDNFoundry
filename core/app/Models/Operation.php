<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /** Keep failure history, but exclude verification failures with a later successful check of the same domain. */
    public function scopeUnresolvedFailures(Builder $query): Builder
    {
        return $query->where('operations.status', 'failed')->where(function (Builder $query): void {
            $query->where('operations.type', '!=', 'domain.nameservers_verify')
                ->orWhereNull('operations.input->domain_id')
                ->orWhereNotExists(function ($recovered): void {
                    $recovered->selectRaw('1')->from('operations as recovered')
                        ->whereColumn('recovered.type', 'operations.type')
                        ->where('recovered.status', 'succeeded')
                        ->whereColumn('recovered.input->domain_id', 'operations.input->domain_id')
                        ->whereColumn('recovered.created_at', '>=', 'operations.created_at')
                        ->whereColumn('recovered.finished_at', '>', 'operations.finished_at');
                });
        });
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
