<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $domain_id
 * @property string $name
 * @property ?string $description
 * @property list<string> $entries canonical CIDR strings
 * @property int $entry_count
 */
class IpList extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['entries' => 'array'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
