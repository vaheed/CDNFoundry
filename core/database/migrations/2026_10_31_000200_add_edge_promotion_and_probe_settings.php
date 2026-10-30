<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULTS = [
        'promotion_quorum_percent' => 100,
        'promotion_grace_seconds' => 120,
        'probe_min_vantage_points' => 2,
        'probe_result_max_age_seconds' => 180,
    ];

    public function up(): void
    {
        $row = DB::table('system_settings')->where('group', 'edge_runtime')->first();
        if ($row === null) {
            return;
        }
        $values = json_decode($row->values, true, flags: JSON_THROW_ON_ERROR);
        DB::table('system_settings')->where('group', 'edge_runtime')->update([
            'values' => json_encode($values + self::DEFAULTS, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('system_settings')->where('group', 'edge_runtime')->first();
        if ($row !== null) {
            $values = array_diff_key(json_decode($row->values, true, flags: JSON_THROW_ON_ERROR), self::DEFAULTS);
            DB::table('system_settings')->where('group', 'edge_runtime')->update(['values' => json_encode($values, JSON_THROW_ON_ERROR)]);
        }
    }
};
