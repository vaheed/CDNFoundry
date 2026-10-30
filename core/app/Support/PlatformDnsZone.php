<?php

namespace App\Support;

use App\Actions\EvaluateEndpointPublication;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use App\Models\PlatformDnsSetting;
use Illuminate\Support\Facades\DB;

final class PlatformDnsZone
{
    /**
     * Give the zone a new SOA serial exactly when its content changes. The serial is
     * monotonic: it is stored, seeded from the clock, and only ever incremented, so
     * secondaries and resolver caches can rely on it. Returns the settings with the
     * current serial.
     */
    public static function advanceSerial(PlatformDnsSetting $settings): PlatformDnsSetting
    {
        return DB::transaction(function () use ($settings): PlatformDnsSetting {
            $locked = PlatformDnsSetting::query()->lockForUpdate()->findOrFail($settings->id);
            $checksum = self::contentChecksum($locked);
            if ($locked->zone_content_checksum !== $checksum) {
                $locked->update([
                    'soa_serial' => $locked->soa_serial === 0 ? max(time(), $locked->revision) : $locked->soa_serial + 1,
                    'zone_content_checksum' => $checksum,
                ]);
            }

            return $locked;
        });
    }

    /** Checksum of everything the zone publishes except the SOA serial. */
    public static function contentChecksum(PlatformDnsSetting $settings): string
    {
        return hash('sha256', json_encode(self::render($settings, 0), JSON_THROW_ON_ERROR));
    }

    /** @return list<array{name:string,type:string,ttl:int,records:list<array{content:string,disabled:bool}>}> */
    public static function render(PlatformDnsSetting $settings, ?int $serial = null): array
    {
        EvaluateEndpointPublication::execute();
        $zone = rtrim($settings->platform_domain, '.').'.';
        $rows = collect([[
            'name' => $zone,
            'type' => 'SOA',
            'ttl' => $settings->soa_minimum_ttl,
            'content' => implode(' ', [
                rtrim($settings->soa_primary, '.').'.',
                rtrim($settings->soa_mailbox, '.').'.',
                $serial ?? $settings->soa_serial,
                $settings->soa_refresh,
                $settings->soa_retry,
                $settings->soa_expire,
                $settings->soa_minimum_ttl,
            ]),
        ]]);

        foreach ($settings->nameservers as $nameserver) {
            $hostname = rtrim($nameserver['hostname'], '.').'.';
            $rows->push(['name' => $zone, 'type' => 'NS', 'ttl' => $settings->default_ttl, 'content' => $hostname]);
            $rows->push(['name' => $hostname, 'type' => 'A', 'ttl' => $settings->default_ttl, 'content' => $nameserver['ipv4']]);
            if (filled($nameserver['ipv6'] ?? null)) {
                $rows->push(['name' => $hostname, 'type' => 'AAAA', 'ttl' => $settings->default_ttl, 'content' => $nameserver['ipv6']]);
            }
        }

        // Customer nameservers get explicit address records only; they are never apex NS records and
        // there is no wildcard, so a hostname resolves exactly when an administrator configured it.
        foreach ($settings->customer_nameserver_pairs ?? [] as $pair) {
            foreach ($pair as $nameserver) {
                $hostname = rtrim($nameserver['hostname'], '.').'.';
                $rows->push(['name' => $hostname, 'type' => 'A', 'ttl' => $settings->default_ttl, 'content' => $nameserver['ipv4']]);
                if (filled($nameserver['ipv6'] ?? null)) {
                    $rows->push(['name' => $hostname, 'type' => 'AAAA', 'ttl' => $settings->default_ttl, 'content' => $nameserver['ipv6']]);
                }
            }
        }

        $proxy = rtrim($settings->proxy_hostname, '.').'.';
        $defaultSharedPool = EdgePool::defaultShared();
        foreach (EdgePool::query()->where('enabled', true)->where('withdrawn', false)->orderBy('id')->get() as $pool) {
            $configuredEndpoints = EdgePoolEndpoint::query()->with(['edge', 'pool'])->where('edge_pool_id', $pool->id)->orderBy('edge_id')->get();
            $endpoints = $configuredEndpoints->where('withdrawn', false)->where('dns_published', true)->values();
            if ($endpoints->isNotEmpty()) {
                if ($pool->isSimpleAnycast()) {
                    foreach (['A' => 'anycast_ipv4', 'AAAA' => 'anycast_ipv6'] as $family => $field) {
                        if (! filled($pool->{$field})) {
                            continue;
                        }
                        $rows->push(['name' => EdgeRoutingCompiler::poolHostname($settings, $pool).'.', 'type' => $family, 'ttl' => $settings->default_ttl, 'content' => $pool->{$field}]);
                        if ($defaultSharedPool?->is($pool)) {
                            $rows->push(['name' => $proxy, 'type' => $family, 'ttl' => $settings->default_ttl, 'content' => $pool->{$field}]);
                        }
                    }

                    continue;
                }
                foreach (['A' => 'ipv4', 'AAAA' => 'ipv6'] as $family => $field) {
                    $familyEndpoints = $endpoints->filter(fn (EdgePoolEndpoint $endpoint): bool => filled($endpoint->{$field}))->values();
                    if ($familyEndpoints->isEmpty()) {
                        continue;
                    }
                    foreach ($familyEndpoints->groupBy(fn (EdgePoolEndpoint $endpoint): string => $endpoint->edge->country_code)->sortKeys() as $code => $group) {
                        self::pushAddresses($rows, EdgeRoutingCompiler::dataHostname($settings, $pool, 'country', $code).'.', $family, $group->pluck($field)->all(), $settings->default_ttl);
                    }
                    foreach ($familyEndpoints->groupBy(fn (EdgePoolEndpoint $endpoint): string => $endpoint->edge->continent_code)->sortKeys() as $code => $group) {
                        self::pushAddresses($rows, EdgeRoutingCompiler::dataHostname($settings, $pool, 'continent', $code).'.', $family, $group->pluck($field)->all(), $settings->default_ttl);
                    }
                    self::pushAddresses($rows, EdgeRoutingCompiler::dataHostname($settings, $pool, 'global', 'all').'.', $family, $familyEndpoints->pluck($field)->all(), $settings->default_ttl);
                    $content = EdgeRoutingCompiler::compileDatabaseLookup($settings, $pool, $family);
                    $rows->push(['name' => EdgeRoutingCompiler::poolHostname($settings, $pool).'.', 'type' => 'LUA', 'ttl' => $settings->default_ttl, 'content' => $content]);
                    if ($defaultSharedPool?->is($pool)) {
                        $rows->push(['name' => $proxy, 'type' => 'LUA', 'ttl' => $settings->default_ttl, 'content' => $content]);
                    }
                }

                continue;
            }
        }

        return $rows->groupBy(fn (array $row): string => $row['name'].'|'.$row['type'])
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'name' => $first['name'],
                    'type' => $first['type'],
                    'ttl' => $group->min('ttl'),
                    'records' => $group->pluck('content')->unique()->sort()->map(fn (string $content): array => ['content' => $content, 'disabled' => false])->values()->all(),
                ];
            })->sortKeys()->values()->all();
    }

    private static function pushAddresses($rows, string $name, string $type, array $addresses, int $ttl): void
    {
        foreach (array_values(array_unique($addresses)) as $address) {
            $rows->push(['name' => $name, 'type' => $type, 'ttl' => $ttl, 'content' => $address]);
        }
    }
}
