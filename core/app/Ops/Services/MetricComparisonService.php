<?php

namespace App\Ops\Services;

final class MetricComparisonService
{
    /** @return array{delta: float|null, percent: float|null, direction: string} */
    public function compare(float|int|null $current, float|int|null $previous): array
    {
        if ($current === null || $previous === null) {
            return ['delta' => null, 'percent' => null, 'direction' => 'unavailable'];
        }

        $delta = (float) $current - (float) $previous;
        $percent = (float) $previous === 0.0 ? null : ($delta / abs((float) $previous)) * 100;

        return [
            'delta' => $delta,
            'percent' => $percent,
            'direction' => abs($delta) < 0.000001 ? 'flat' : ($delta > 0 ? 'up' : 'down'),
        ];
    }
}
