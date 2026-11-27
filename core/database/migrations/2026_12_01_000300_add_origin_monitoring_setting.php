<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expand step: the platform switch for active origin monitoring. A new installation has it off. An installation where any
     * proxied origin already has a scheduled check keeps monitoring on, so the upgrade changes no behavior; an administrator can
     * turn it off at any time.
     */
    public function up(): void
    {
        $scheduled = false;
        foreach (DB::table('dns_records')->where('mode', 'proxied')->whereNotNull('origin')->select('origin')->cursor() as $row) {
            $origin = json_decode((string) $row->origin, true);
            if (is_array($origin) && ($origin['health_check']['enabled'] ?? false) === true) {
                $scheduled = true;
                break;
            }
        }
        DB::table('system_settings')->insertOrIgnore([
            'group' => 'origin_monitoring', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            'values' => json_encode(['enabled' => $scheduled, 'minimum_interval_seconds' => 60, 'max_representatives' => 3, 'manual_tests_when_off' => false, 'domain_probes_per_minute' => 300]),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'origin_monitoring')->delete();
    }
};
