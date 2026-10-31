<?php

namespace App\Support;

use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;

/**
 * The platform-wide origin destination policy every PoP applies to every proxied origin. It is
 * delivered once per PoP, not copied into each domain snapshot, so a pool address change does not
 * recompile or re-sign any domain. Every list is a sorted set, so equal policy has an equal checksum.
 */
final class OriginPolicy
{
    /** @return array{private_allowlist: list<string>, blocked_networks: list<string>, blocked_addresses: list<string>} */
    public static function compile(): array
    {
        $settings = app(PlatformSettings::class);
        $addresses = Edge::query()->pluck('management_ipv4')->merge(Edge::query()->pluck('management_ipv6'))
            ->merge(EdgePoolEndpoint::query()->pluck('ipv4'))->merge(EdgePoolEndpoint::query()->pluck('ipv6'))
            ->merge(EdgePool::query()->pluck('anycast_ipv4'))->merge(EdgePool::query()->pluck('anycast_ipv6'))
            ->filter()->merge($settings->get('origin_safety', 'blocked_origin_addresses'));

        return [
            'private_allowlist' => self::sortedSet($settings->get('origin_safety', 'private_origin_allowlist')),
            'blocked_networks' => self::sortedSet($settings->get('origin_safety', 'blocked_origin_networks')),
            'blocked_addresses' => self::sortedSet($addresses->all()),
        ];
    }

    /**
     * @return list<string>
     */
    public static function sortedSet(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])->map(fn ($value) => (string) $value)->unique()->sort(SORT_STRING)->values()->all();
    }
}
