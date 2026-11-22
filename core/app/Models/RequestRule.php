<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $domain_id
 * @property string $type
 * @property string|null $name
 * @property bool $enabled
 * @property int $priority
 * @property array $conditions
 * @property array $action
 * @property string|null $preset
 * @property string $fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RequestRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'priority' => 'integer', 'conditions' => 'array', 'action' => 'array'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
