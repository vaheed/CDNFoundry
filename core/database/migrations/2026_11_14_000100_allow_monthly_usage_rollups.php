<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Expand step: usage kept for years is summed into one row per month, the retention settings used by the history prune, and the indexes it needs. Readers that ignore the granularity keep working. */
    private const SETTINGS = [
        'usage_hourly_retention_days' => 35, 'usage_daily_retention_days' => 365, 'edge_task_retention_days' => 30,
        'cache_purge_retention_days' => 90, 'tls_order_retention_days' => 180,
    ];

    public function up(): void
    {
        // The stored settings row must hold every field, or the whole group is refused as invalid.
        $row = DB::table('system_settings')->where('group', 'operations')->first();
        if ($row !== null) {
            $values = json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR);
            DB::table('system_settings')->where('group', 'operations')->update(['values' => json_encode($values + self::SETTINGS), 'updated_at' => now()]);
        }
        // The daily prune walks finished rows by age.
        DB::statement('CREATE INDEX IF NOT EXISTS edge_tasks_status_updated_at_index ON edge_tasks (status, updated_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS cache_purges_status_updated_at_index ON cache_purges (status, updated_at)');
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE usage_rollups DROP CONSTRAINT IF EXISTS usage_rollups_granularity_check');
        DB::statement("ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_granularity_check CHECK (granularity IN ('hour', 'day', 'month'))");
    }

    public function down(): void
    {
        $row = DB::table('system_settings')->where('group', 'operations')->first();
        if ($row !== null) {
            $values = array_diff_key(json_decode((string) $row->values, true, 32, JSON_THROW_ON_ERROR), self::SETTINGS);
            DB::table('system_settings')->where('group', 'operations')->update(['values' => json_encode($values), 'updated_at' => now()]);
        }
        DB::statement('DROP INDEX IF EXISTS cache_purges_status_updated_at_index');
        DB::statement('DROP INDEX IF EXISTS edge_tasks_status_updated_at_index');
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('usage_rollups')->where('granularity', 'month')->exists()) {
            throw new RuntimeException('Monthly usage rows exist; rolling back would lose the ability to store them.');
        }
        DB::statement('ALTER TABLE usage_rollups DROP CONSTRAINT IF EXISTS usage_rollups_granularity_check');
        DB::statement("ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_granularity_check CHECK (granularity IN ('hour', 'day'))");
    }
};
