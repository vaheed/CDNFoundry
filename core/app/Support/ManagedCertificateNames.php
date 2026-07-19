<?php

namespace App\Support;

use App\Models\Domain;

final class ManagedCertificateNames
{
    /** @return list<list<string>> */
    public static function requiredSets(Domain $domain): array
    {
        $hosts = $domain->dnsRecords()->where('mode', 'proxied')->orderBy('name')->pluck('name')
            ->map(fn (string $name): string => strtolower(rtrim($name, '.')))->unique()->values();
        if ($hosts->isEmpty()) {
            return [];
        }
        $sets = [[$domain->name, '*.'.$domain->name]];
        $deep = $hosts->filter(fn (string $name): bool => ! self::coveredBy($name, $sets[0]))->values();
        foreach ($deep->chunk(50) as $chunk) {
            $sets[] = $chunk->all();
        }

        return $sets;
    }

    /** @param list<string> $names */
    public static function coveredBy(string $hostname, array $names): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        foreach ($names as $name) {
            $name = strtolower(rtrim($name, '.'));
            if ($name === $hostname) {
                return true;
            }
            if (str_starts_with($name, '*.') && str_ends_with($hostname, substr($name, 1))
                && substr_count($hostname, '.') === substr_count($name, '.')) {
                return true;
            }
        }

        return false;
    }
}
