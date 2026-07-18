<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class DnsZoneValidator
{
    public static function assertValid(Collection $rows, string $zone): void
    {
        $zone = rtrim(strtolower($zone), '.');
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
            $publishedCnameRows = $ownerRows
                ->where('type', 'CNAME')
                ->reject(fn (array $row): bool => ($row['mode'] ?? 'dns_only') === 'proxied');
            if ($publishedCnameRows->isNotEmpty() && ($types->count() > 1 || $ownerRows->count() > 1)) {
                throw ValidationException::withMessages(['records' => "CNAME cannot coexist with other records at $name."]);
            }
            $proxiedRows = $ownerRows->where('mode', 'proxied');
            if ($proxiedRows->count() > 1) {
                throw ValidationException::withMessages(['records' => "A proxied hostname must have exactly one record and one origin at $name."]);
            }
            if ($proxiedRows->isNotEmpty()) {
                $proxiedRow = $proxiedRows->first();
                $competingRoutingRows = $ownerRows->reject(fn (array $row): bool => $row === $proxiedRow)
                    ->whereIn('type', ['A', 'AAAA', 'CNAME']);
                if ($competingRoutingRows->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'records' => "A proxied hostname cannot coexist with another A, AAAA, or CNAME at $name. Edit or remove the existing address/alias record.",
                    ]);
                }
                if (strtolower((string) $name) !== $zone && $ownerRows->count() > 1) {
                    throw ValidationException::withMessages([
                        'records' => "A non-apex proxied hostname publishes as a CNAME and must be the only record at $name.",
                    ]);
                }
            }
            foreach ($ownerRows->groupBy('type') as $typeRows) {
                if ($typeRows->where('mode', 'geo_dns')->count() > 1 || ($typeRows->where('mode', 'geo_dns')->isNotEmpty() && $typeRows->count() > 1)) {
                    throw ValidationException::withMessages(['records' => "A Geo-DNS record must be the only record of its type at $name."]);
                }
            }
        }
    }
}
