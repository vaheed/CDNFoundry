<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class DnsZoneValidator
{
    public static function assertValid(Collection $rows): void
    {
        $logical = [];
        foreach ($rows as $row) {
            $key = implode('|', [$row['name'], $row['type'], $row['content'], $row['priority'], $row['weight'], $row['port'], $row['mode'] ?? 'dns_only']);
            if (isset($logical[$key])) {
                throw ValidationException::withMessages(['records' => 'The zone contains a duplicate logical DNS record.']);
            }
            $logical[$key] = true;
        }
        foreach ($rows->groupBy('name') as $name => $ownerRows) {
            $types = $ownerRows->pluck('type')->unique();
            if ($types->contains('CNAME') && ($types->count() > 1 || $ownerRows->count() > 1)) {
                throw ValidationException::withMessages(['records' => "CNAME cannot coexist with other records at $name."]);
            }
            foreach ($ownerRows->groupBy('type') as $typeRows) {
                if ($typeRows->where('mode', 'geo_dns')->count() > 1 || ($typeRows->where('mode', 'geo_dns')->isNotEmpty() && $typeRows->count() > 1)) {
                    throw ValidationException::withMessages(['records' => "A Geo-DNS record must be the only record of its type at $name."]);
                }
                if ($typeRows->where('mode', 'proxied')->count() > 1 || ($typeRows->where('mode', 'proxied')->isNotEmpty() && $typeRows->count() > 1)) {
                    throw ValidationException::withMessages(['records' => "A proxied hostname must be the only record of its type at $name."]);
                }
            }
        }
    }
}
