<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

class Operation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The typed column is what authorization and uniqueness use; callers keep passing it in the input.
        static::creating(function (self $operation): void {
            $domainId = $operation->input['domain_id'] ?? null;
            if ($operation->domain_id === null && (is_int($domainId) || (is_string($domainId) && ctype_digit($domainId)))
                && Domain::query()->whereKey((int) $domainId)->exists()) {
                $operation->domain_id = (int) $domainId;
            }
        });
    }

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime'];
    }

    /**
     * Keep failure history, but exclude failures an operator acknowledged and failures a later
     * successful operation of the same type for the same domain (or the same global type) superseded.
     */
    public function scopeUnresolvedFailures(Builder $query): Builder
    {
        return $query->where('operations.status', 'failed')->whereNull('operations.acknowledged_at')
            ->whereNotExists(function ($recovered): void {
                $recovered->selectRaw('1')->from('operations as recovered')
                    ->whereColumn('recovered.type', 'operations.type')
                    ->where('recovered.status', 'succeeded')
                    ->where(fn ($same) => $same->whereColumn('recovered.input->domain_id', 'operations.input->domain_id')
                        ->orWhere(fn ($global) => $global->whereNull('recovered.input->domain_id')->whereNull('operations.input->domain_id')))
                    ->whereColumn('recovered.finished_at', '>', 'operations.finished_at');
            });
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** An accepted request's pollable receipt for work tracked by another record. */
    public static function receipt(string $type, array $input, ?int $actorId, string $status = 'running'): self
    {
        return self::query()->create([
            'actor_id' => $actorId, 'type' => $type, 'status' => $status, 'input' => $input,
            'started_at' => now(), 'finished_at' => $status === 'running' ? null : now(),
        ]);
    }

    public static function coalesceDomain(string $type, int $domainId, ?int $actorId = null): self
    {
        $unfinished = fn () => self::query()->where('type', $type)->where('domain_id', $domainId)->whereIn('status', ['pending', 'running'])->first();
        try {
            return $unfinished() ?? self::query()->create([
                'actor_id' => $actorId, 'domain_id' => $domainId, 'type' => $type, 'status' => 'pending', 'input' => ['domain_id' => $domainId],
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request created the unfinished operation first.
            return $unfinished() ?? throw $exception;
        }
    }
}
