<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformRuleset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'applies_to_all' => 'boolean'];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PlatformRulesetRule::class);
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'platform_ruleset_domains');
    }

    /** Rulesets that are switched on and reach the domain: all domains, or the domain is on the chosen list. */
    public function scopeAppliedTo($query, Domain $domain)
    {
        return $query->where('enabled', true)->where(fn ($inner) => $inner->where('applies_to_all', true)
            ->orWhereExists(fn ($exists) => $exists->selectRaw('1')->from('platform_ruleset_domains')
                ->whereColumn('platform_ruleset_domains.platform_ruleset_id', 'platform_rulesets.id')->where('platform_ruleset_domains.domain_id', $domain->id)));
    }
}
