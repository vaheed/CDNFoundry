<?php

namespace App\Support;

use App\Models\PlatformDnsSetting;

/**
 * The readable nameserver pairs a platform offers for delegation. Pair N is the Nth two
 * consecutive entries of the platform nameserver list, so ns1 and ns2 are one pair and
 * ns3 and ns4 the next. A claim is delegated to one pair; a competing claim for the same
 * name gets a different pair, which is what lets the true registrant prove control.
 */
final class NameserverPairs
{
    /** @return list<list<string>> each pair sorted, in platform order */
    public static function all(?PlatformDnsSetting $settings = null): array
    {
        $settings ??= PlatformDnsSetting::query()->find(1);
        $hostnames = collect($settings?->nameservers ?? [])->pluck('hostname')
            ->map(fn (string $hostname): string => strtolower(rtrim($hostname, '.')))->values()->all();
        $pairs = [];
        foreach (array_chunk($hostnames, 2) as $pair) {
            if (count($pair) === 2) {
                sort($pair);
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * Choose a pair for a new claim that no other live claim of the name already uses.
     *
     * @param  list<list<string>>  $taken  pairs in use by other live domains with the same name
     * @return list<string>|null null when every pair is taken
     */
    public static function choose(string $name, array $taken, ?PlatformDnsSetting $settings = null): ?array
    {
        $free = array_values(array_filter(self::all($settings), fn (array $pair): bool => ! in_array($pair, $taken, true)));
        if ($free === []) {
            return null;
        }

        return $free[abs(crc32($name)) % count($free)];
    }
}
