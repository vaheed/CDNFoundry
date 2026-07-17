<?php

namespace App\Support;

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
            $rows->push(['name' => $hostname, 'type' => 'AAAA', 'ttl' => $settings->default_ttl, 'content' => $nameserver['ipv6']]);
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
}
