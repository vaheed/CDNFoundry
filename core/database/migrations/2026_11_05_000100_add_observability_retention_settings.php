<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULTS = [
        'operations' => ['failed_operations_window_minutes' => 60],
        'telemetry' => ['one_minute_retention_hours' => 48, 'prometheus_retention_days' => 30, 'loki_retention_days' => 14],
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $group => $defaults) {
            $row = DB::table('system_settings')->where('group', $group)->first();
            if ($row === null) {
                continue;
            }
            $values = json_decode($row->values, true, flags: JSON_THROW_ON_ERROR);
            DB::table('system_settings')->where('group', $group)->update([
                'values' => json_encode($values + $defaults, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::DEFAULTS as $group => $defaults) {
            $row = DB::table('system_settings')->where('group', $group)->first();
            if ($row !== null) {
                $values = array_diff_key(json_decode($row->values, true, flags: JSON_THROW_ON_ERROR), $defaults);
                DB::table('system_settings')->where('group', $group)->update(['values' => json_encode($values, JSON_THROW_ON_ERROR)]);
            }
        }
    }
};
