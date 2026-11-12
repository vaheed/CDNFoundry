<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: edge-reported security events keep one row per domain, edge, reason, and hour, and two retention
     * settings bound security events and finished operations. Old releases ignore the new column and settings.
     */
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table): void {
            $table->timestampTz('bucket_at')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX security_events_hourly_bucket_unique ON security_events (domain_id, edge_id, reason_code, bucket_at) WHERE bucket_at IS NOT NULL');
        DB::statement('CREATE INDEX security_events_occurred_at_index ON security_events (occurred_at)');
        DB::statement('CREATE INDEX operations_finished_at_index ON operations (finished_at) WHERE finished_at IS NOT NULL');

        $row = DB::table('system_settings')->where('group', 'operations')->first();
        if ($row !== null) {
            $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
            $values += ['security_event_retention_days' => 30, 'operation_retention_days' => 90];
            DB::table('system_settings')->where('group', 'operations')->update(['values' => json_encode($values), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS operations_finished_at_index');
        DB::statement('DROP INDEX IF EXISTS security_events_occurred_at_index');
        DB::statement('DROP INDEX IF EXISTS security_events_hourly_bucket_unique');
        Schema::table('security_events', function (Blueprint $table): void {
            $table->dropColumn('bucket_at');
        });
        $row = DB::table('system_settings')->where('group', 'operations')->first();
        if ($row !== null) {
            $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
            unset($values['security_event_retention_days'], $values['operation_retention_days']);
            DB::table('system_settings')->where('group', 'operations')->update(['values' => json_encode($values), 'updated_at' => now()]);
        }
    }
};
