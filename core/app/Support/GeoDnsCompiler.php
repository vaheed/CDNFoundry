<?php

namespace App\Support;

final class GeoDnsCompiler
{
    public static function compile(string $type, array $config): string
    {
        $parts = [';local cc=string.upper(countryCode() or "--");local cn=string.upper(continentCode() or "--");'];
        foreach ($config['countries'] as $code => $targets) {
            $parts[] = 'if cc=='.self::quote($code).' then return '.self::targets($targets).' end;';
        }
        foreach ($config['continents'] as $code => $targets) {
            $parts[] = 'if cn=='.self::quote($code).' then return '.self::targets($targets).' end;';
        }
        $parts[] = 'return '.self::targets($config['default']);

        return $type.' '.implode('', $parts);
    }

    private static function targets(array $targets): string
    {
        return '{'.implode(',', array_map(self::quote(...), $targets)).'}';
    }

    private static function quote(string $value): string
    {
        return '"'.addcslashes($value, "\\\"").'"';
    }
}
