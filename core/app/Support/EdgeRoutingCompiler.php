<?php

namespace App\Support;

use App\Models\EdgePool;
use App\Models\PlatformDnsSetting;

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
        if ($pool->isSimpleAnycast()) {
            $type = $family === 'AAAA' ? 'AAAA' : 'A';
            $target = self::poolHostname($settings, $pool).'.';

            return $type.' "return dblookup(\''.$target.'\',pdns.'.$type.')"';
        }

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
}
