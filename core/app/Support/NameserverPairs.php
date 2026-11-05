<?php

namespace App\Support;

use App\Models\DnsCluster;
use App\Models\PlatformDnsDeployment;
use App\Models\PlatformDnsSetting;

/**
 * The readable nameserver pairs a platform offers customers for delegation, configured by an
 * administrator separately from the platform's own apex NS set. A claim is delegated to one
 * pair; a competing claim for the same name gets a different pair, which is what lets the
 * true registrant prove control by changing the parent delegation.
 */
final class NameserverPairs
{
    /** @return list<list<string>> each pair's hostnames sorted, in configured order */
    public static function all(?PlatformDnsSetting $settings = null): array
    {
        $settings ??= PlatformDnsSetting::query()->find(1);

        return collect($settings?->customer_nameserver_pairs ?? [])->map(
            fn (array $pair): array => collect($pair)->pluck('hostname')->map(fn (string $hostname): string => strtolower(rtrim($hostname, '.')))->sort()->values()->all()
        )->filter(fn (array $pair): bool => count($pair) === 2)->values()->all();
    }

    /**
     * Whether every enabled DNS cluster that serves the platform zone already publishes this pair's
     * address records, so a customer is never told to delegate to a name that does not resolve yet.
     * A cluster the platform zone has not been pushed to yet serves no delegation either way.
     *
     * @param  list<string>  $pair
     */
    public static function deployed(array $pair): bool
    {
        $clusterIds = DnsCluster::query()->where('enabled', true)->pluck('id');
        foreach (PlatformDnsDeployment::query()->whereIn('dns_cluster_id', $clusterIds)->get() as $deployment) {
            $published = collect($deployment->active_rrsets ?? [])->where('type', 'A')->pluck('name')
                ->map(fn (string $name): string => strtolower(rtrim($name, '.')))->all();
            if ($deployment->status !== 'succeeded' || array_diff($pair, $published) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Choose a deployed pair that neither the current parent delegation nor another live claim on
     * the name uses. Null means no pair is available; a pair is never reused.
     *
     * @param  list<list<string>>  $taken  pairs reserved by other live claims on this name
     * @param  list<string>  $current  the delegation the parent zone publishes now
     * @return list<string>|null
     */
    public static function choose(string $name, array $taken, array $current = [], ?PlatformDnsSetting $settings = null): ?array
    {
        sort($current);
        $free = array_values(array_filter(
            self::all($settings),
            fn (array $pair): bool => ! in_array($pair, $taken, true) && $pair !== $current && self::deployed($pair),
        ));

        return $free === [] ? null : $free[abs(crc32($name)) % count($free)];
    }
}
