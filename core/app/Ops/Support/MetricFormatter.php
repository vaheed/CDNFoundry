<?php

namespace App\Ops\Support;

final class MetricFormatter
{
    public function bytes(float|int|null $bytes, bool $perSecond = false): string
    {
        if ($bytes === null) {
            return 'Unavailable';
        }

        $value = max(0, (float) $bytes);
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $index = $value > 0 ? min((int) floor(log($value, 1024)), count($units) - 1) : 0;

        return number_format($value / (1024 ** $index), $index === 0 ? 0 : 1).' '.$units[$index].($perSecond ? '/s' : '');
    }

    public function number(float|int|null $value, int $precision = 0): string
    {
        return $value === null ? 'Unavailable' : number_format((float) $value, $precision);
    }

    public function percent(float|int|null $ratio, int $precision = 1): string
    {
        return $ratio === null ? 'Unavailable' : number_format((float) $ratio * 100, $precision).'%';
    }

    public function milliseconds(float|int|null $value): string
    {
        return $value === null ? 'Unavailable' : number_format((float) $value, 1).' ms';
    }

    public function deltaPercent(?float $value): string
    {
        if ($value === null) {
            return 'No comparable baseline';
        }

        return ($value > 0 ? '+' : '').number_format($value, 1).'% vs previous period';
    }
}
