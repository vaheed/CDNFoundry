<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Expand step: the per-account and per-worker probe budgets of active origin monitoring. */
    public function up(): void
    {
        $row = DB::table('system_settings')->where('group', 'origin_monitoring')->first();
        if ($row === null) {
            return;
        }
        $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
        $values += ['account_probes_per_minute' => 300, 'worker_slot_seconds_per_minute' => 240];
        DB::table('system_settings')->where('group', 'origin_monitoring')->update(['values' => json_encode($values), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $row = DB::table('system_settings')->where('group', 'origin_monitoring')->first();
        if ($row === null) {
            return;
        }
        $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
        unset($values['account_probes_per_minute'], $values['worker_slot_seconds_per_minute']);
        DB::table('system_settings')->where('group', 'origin_monitoring')->update(['values' => json_encode($values), 'updated_at' => now()]);
    }
};
