<?php

namespace App\Support;

use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use App\Models\PlatformDnsSetting;

final class PlatformDnsZone
{
    /** @return list<array{name:string,type:string,ttl:int,records:list<array{content:string,disabled:bool}>}> */
    public static function render(PlatformDnsSetting $settings): array
    {
        $zone = rtrim($settings->platform_domain, '.').'.';
        $rows = collect([[
            'name' => $zone,
            'type' => 'SOA',
            'ttl' => $settings->soa_minimum_ttl,
            'content' => implode(' ', [
                rtrim($settings->soa_primary, '.').'.',
                rtrim($settings->soa_mailbox, '.').'.',
                $settings->revision,
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

        $proxy = rtrim($settings->proxy_hostname, '.').'.';
        $defaultSharedPool = EdgePool::query()->where('enabled', true)->where('withdrawn', false)->where('kind', 'shared')->orderBy('id')->first();
        foreach (EdgePool::query()->where('enabled', true)->where('withdrawn', false)->orderBy('id')->get() as $pool) {
            $configuredEndpoints = EdgePoolEndpoint::query()->with(['edge', 'pool'])->where('edge_pool_id', $pool->id)->orderBy('edge_id')->get();
            $endpoints = $configuredEndpoints->where('withdrawn', false)
                ->filter(fn (EdgePoolEndpoint $endpoint): bool => $endpoint->isReady())->values();
            if ($endpoints->isNotEmpty()) {
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
                    'records' => $group->pluck('content')->sort()->map(fn (string $content): array => ['content' => $content, 'disabled' => false])->values()->all(),
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
