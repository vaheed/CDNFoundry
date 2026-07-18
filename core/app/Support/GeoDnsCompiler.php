<?php

namespace App\Support;

final class GeoDnsCompiler
{
    public static function compile(string $type, array $config, int $priority = 0, int $weight = 0, int $port = 0): string
    {
        $config = self::runtimeConfig($type, $config, $priority, $weight, $port);
        $parts = [";local cc=string.upper(countryCode() or '--');local cn=string.upper(continentCode() or '--');"];
        foreach ($config['countries'] as $code => $targets) {
            $parts[] = 'if cc=='.self::quote($code).' then return '.self::targets($targets).' end;';
        }
        foreach ($config['continents'] as $code => $targets) {
            $parts[] = 'if cn=='.self::quote($code).' then return '.self::targets($targets).' end;';
        }
        $parts[] = 'return '.self::targets($config['default']);

        $script = implode('', $parts);

        return $type.' "'.addcslashes($script, '"\\').'"';
    }

    private static function runtimeConfig(string $type, array $config, int $priority, int $weight, int $port): array
    {
        $format = static fn (string $target): string => match ($type) {
            'MX' => $priority.' '.$target,
            'SRV' => implode(' ', [$priority, $weight, $port, $target]),
            default => $target,
        };
        $map = static fn (array $targets): array => array_map($format, $targets);

        return [
            'default' => $map($config['default']),
            'countries' => collect($config['countries'])->map($map)->all(),
            'continents' => collect($config['continents'])->map($map)->all(),
        ];
    }

    private static function targets(array $targets): string
    {
        return '{'.implode(',', array_map(self::quote(...), $targets)).'}';
    }

    private static function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
