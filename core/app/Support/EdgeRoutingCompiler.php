<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class EdgeRoutingCompiler
{
    public static function compile(Collection $edges, string $family): ?string
    {
        $field = $family === 'AAAA' ? 'ipv6' : 'ipv4';
        $edges = $edges->filter(fn ($edge): bool => filled($edge->{$field}))->sortBy('id')->values();
        if ($edges->isEmpty()) {
            return null;
        }
        $parts = [";local cc=string.upper(countryCode() or '--');local cn=string.upper(continentCode() or '--');"];
        foreach ($edges->groupBy('country_code')->sortKeys() as $code => $countryEdges) {
            $parts[] = 'if cc=='.self::quote($code).' then return '.self::addresses($countryEdges->pluck($field)->all()).' end;';
        }
        foreach ($edges->groupBy('continent_code')->sortKeys() as $code => $continentEdges) {
            $parts[] = 'if cn=='.self::quote($code).' then return '.self::addresses($continentEdges->pluck($field)->all()).' end;';
        }
        $parts[] = 'return '.self::addresses($edges->pluck($field)->all());

        return $family.' "'.implode('', $parts).'"';
    }

    private static function addresses(array $addresses): string
    {
        return '{'.implode(',', array_map(self::quote(...), array_values(array_unique($addresses)))).'}';
    }

    private static function quote(string $value): string
    {
        return "'".$value."'";
    }
}
