<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Expand step: one platform-wide switch for diagnostic response headers, hidden by default. */
    public function up(): void
    {
        $row = DB::table('system_settings')->where('group', 'proxy_defaults')->first();
        if ($row === null) {
            return;
        }
        $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
        if (array_key_exists('diagnostic_response_headers', $values)) {
            return;
        }
        $values['diagnostic_response_headers'] = false;
        DB::table('system_settings')->where('group', 'proxy_defaults')->update(['values' => json_encode($values), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $row = DB::table('system_settings')->where('group', 'proxy_defaults')->first();
        if ($row === null) {
            return;
        }
        $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
        unset($values['diagnostic_response_headers']);
        DB::table('system_settings')->where('group', 'proxy_defaults')->update(['values' => json_encode($values), 'updated_at' => now()]);
    }
};
