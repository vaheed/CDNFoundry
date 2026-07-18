<?php

namespace App\Support;

use App\Models\EdgePool;
use App\Models\PlatformDnsSetting;
use Illuminate\Support\Collection;

final class EdgeRoutingCompiler
{
    public static function poolHostname(PlatformDnsSetting $settings, EdgePool $pool): string
    {
        return self::poolPrefix($pool).'.'.rtrim($settings->proxy_hostname, '.');
    }

    public static function poolPrefix(EdgePool $pool): string
    {
        return 'pool-'.$pool->getKey();
    }

    public static function dataHostname(PlatformDnsSetting $settings, EdgePool $pool, string $scope, string $code): string
    {
        return implode('.', [self::poolPrefix($pool), $scope, strtolower($code), rtrim($settings->proxy_hostname, '.')]);
    }

    public static function compileDatabaseLookup(PlatformDnsSetting $settings, EdgePool $pool, string $family): string
    {
        $type = $family === 'AAAA' ? 'AAAA' : 'A';
        $country = self::dataHostname($settings, $pool, 'country', "'..string.lower(cc)..'");
        $continent = self::dataHostname($settings, $pool, 'continent', "'..string.lower(cn)..'");
        $global = self::dataHostname($settings, $pool, 'global', 'all');
        $script = ";local cc=string.upper(countryCode() or '--');local cn=string.upper(continentCode() or '--');";
        $script .= "local v=dblookup('{$country}.',pdns.{$type});";
        $script .= "if #v==0 then v=dblookup('{$continent}.',pdns.{$type}) end;";
        $script .= "if #v==0 then v=dblookup('{$global}.',pdns.{$type}) end;";
        $script .= 'if #v==0 then return {} end;return pickhashed(v)';

        return $type.' "'.$script.'"';
    }

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
