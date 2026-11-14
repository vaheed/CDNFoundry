<?php

namespace App\Models;

use App\Support\Impersonation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address'])]
class AuditLog extends Model
{
    public $timestamps = false;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function record(
        ?User $actor,
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): self {
        // A change made while an administrator acts as a user is the user's change, made by that administrator.
        $impersonator = Impersonation::impersonatorId();
        if ($impersonator !== null && ! str_starts_with($action, 'user.impersonation_')) {
            $metadata['impersonated_by'] = $impersonator;
        }

        return self::query()->create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
