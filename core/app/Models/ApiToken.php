<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\PersonalAccessToken;

class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public const ABILITIES = ['*', 'read', 'dns', 'purge'];

    public const DEFAULT_LIFETIME_DAYS = 90;

    public const MAX_LIFETIME_DAYS = 365;

    public const MAX_DOMAINS = 50;

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'personal_access_token_domains', 'personal_access_token_id', 'domain_id');
    }

    public function isFullAccess(): bool
    {
        return in_array('*', $this->abilities ?? [], true);
    }

    /** @return list<int>|null null when the token is not limited to particular domains */
    public function scopedDomainIds(): ?array
    {
        $ids = $this->domains()->pluck('domains.id')->map(fn ($id): int => (int) $id)->all();

        return $ids === [] ? null : $ids;
    }
}
