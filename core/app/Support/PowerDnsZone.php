<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\PlatformDnsSetting;
use RuntimeException;

final class PowerDnsZone
{
    /** @return list<array{name:string,type:string,ttl:int,records:list<array{content:string,disabled:bool}>}> */
    public static function render(Domain $domain): array
    {
        $settings = PlatformDnsSetting::query()->find(1);
        if ($settings === null) {
            throw new RuntimeException('Platform DNS identity must be configured before zone reconciliation.');
        }

        $rows = collect([[
            'name' => $domain->name.'.', 'type' => 'SOA', 'ttl' => $settings->soa_minimum_ttl,
            'content' => implode(' ', [rtrim($settings->soa_primary, '.').'.', rtrim($settings->soa_mailbox, '.').'.', $domain->revision, $settings->soa_refresh, $settings->soa_retry, $settings->soa_expire, $settings->soa_minimum_ttl]),
        ]]);
        foreach ($settings->nameservers as $nameserver) {
            $rows->push(['name' => $domain->name.'.', 'type' => 'NS', 'ttl' => $settings->default_ttl, 'content' => rtrim($nameserver['hostname'], '.').'.']);
        }
        foreach ($domain->dnsRecords()->orderBy('id')->get() as $record) {
            if ($record->mode === 'geo_dns') {
                $rows->push(['name' => $record->name.'.', 'type' => 'LUA', 'ttl' => $record->ttl, 'content' => GeoDnsCompiler::compile($record->type, $record->geo_config)]);

                continue;
            }
            $content = match ($record->type) {
                'MX' => $record->priority.' '.$record->content,
                'SRV' => implode(' ', [$record->priority, $record->weight, $record->port, $record->content]),
                'TXT' => '"'.addcslashes($record->content, '"\\').'"',
                default => $record->content,
            };
            $rows->push(['name' => $record->name.'.', 'type' => $record->type, 'ttl' => $record->ttl, 'content' => $content]);
        }

        return $rows->groupBy(fn (array $row): string => $row['name'].'|'.$row['type'])
            ->map(function ($group): array {
                $first = $group->first();

                return ['name' => $first['name'], 'type' => $first['type'], 'ttl' => $group->min('ttl'), 'records' => $group->pluck('content')->sort()->map(fn (string $content): array => ['content' => $content, 'disabled' => false])->values()->all()];
            })->sortKeys()->values()->all();
    }
}
