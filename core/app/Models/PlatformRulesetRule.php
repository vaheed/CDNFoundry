<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $mode
 * @property ?CarbonInterface $expires_at
 */
class PlatformRulesetRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'conditions' => 'array', 'rate_limit' => 'array', 'expires_at' => 'datetime'];
    }

    public function ruleset(): BelongsTo
    {
        return $this->belongsTo(PlatformRuleset::class, 'platform_ruleset_id');
    }
}
