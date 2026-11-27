<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per built usage hour. A usage row that is absent is a known zero only inside an hour recorded here as
 * finalized; inside an incomplete or an unbuilt hour it means nothing was learned.
 */
#[Fillable(['interval_start', 'interval_end', 'status', 'reason', 'domains_with_traffic', 'built_at'])]
class UsageCoverage extends Model
{
    protected $table = 'usage_coverage';

    public $timestamps = false;

    /**
     * What the stored usage of a range can be trusted for, by whole hours.
     *
     * @return array{hours: int, complete: int, incomplete: int, unbuilt: int, last_built_at: string|null}
     */
    public static function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = $from->copy()->utc()->startOfHour();
        $end = $to->copy()->utc()->startOfHour();
        $hours = max(0, (int) $start->diffInHours($end));
        $rows = self::query()->where('interval_start', '>=', $start)->where('interval_start', '<', $end)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $complete = (int) ($rows['finalized'] ?? 0);
        $incomplete = (int) ($rows['incomplete'] ?? 0);

        return [
            'hours' => $hours, 'complete' => $complete, 'incomplete' => $incomplete, 'unbuilt' => max(0, $hours - $complete - $incomplete),
            'last_built_at' => self::query()->max('built_at'),
        ];
    }

    protected function casts(): array
    {
        return ['interval_start' => 'immutable_datetime', 'interval_end' => 'immutable_datetime', 'built_at' => 'immutable_datetime'];
    }
}
