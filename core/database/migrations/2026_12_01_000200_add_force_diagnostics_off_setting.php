<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Expand step: the platform switch that turns diagnostic headers off for every domain. Off by default, so nothing changes. */
    public function up(): void
    {
        $row = DB::table('system_settings')->where('group', 'public_headers')->first();
        if ($row === null) {
            return;
        }
        $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
        if (array_key_exists('diagnostics_force_off', $values)) {
            return;
        }
        $values['diagnostics_force_off'] = false;
        DB::table('system_settings')->where('group', 'public_headers')->update(['values' => json_encode($values), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $row = DB::table('system_settings')->where('group', 'public_headers')->first();
        if ($row === null) {
            return;
        }
        $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
        unset($values['diagnostics_force_off']);
        DB::table('system_settings')->where('group', 'public_headers')->update(['values' => json_encode($values), 'updated_at' => now()]);
    }
};
