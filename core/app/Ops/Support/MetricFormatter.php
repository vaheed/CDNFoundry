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

    /** Change against the previous period, as a sentence. A missing or zero baseline is never invented. */
    public function change(float|int|null $current, float|int|null $previous): string
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return 'No comparable baseline';
        }

        $percent = (((float) $current - (float) $previous) / abs((float) $previous)) * 100;

        return ($percent > 0 ? '+' : '').number_format($percent, 1).'% vs previous period';
    }
}
