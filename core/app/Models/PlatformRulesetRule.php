<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformRulesetRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'conditions' => 'array', 'rate_limit' => 'array'];
    }

    public function ruleset(): BelongsTo
    {
        return $this->belongsTo(PlatformRuleset::class, 'platform_ruleset_id');
    }
}
