<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS domain_edge_placements_health_idx ON domain_edge_placements (state, desired_revision, domain_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS edge_cells_health_idx ON edge_cells (status, drained, edge_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS cache_purges_health_idx ON cache_purges (status, created_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS usage_rollups_health_idx ON usage_rollups (status, interval_end)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS backups_health_idx ON backups (status, verified_at)');

            return;
        }

        Schema::table('domain_edge_placements', fn (Blueprint $table) => $table->index(['state', 'desired_revision', 'domain_id'], 'domain_edge_placements_health_idx'));
        Schema::table('edge_cells', fn (Blueprint $table) => $table->index(['status', 'drained', 'edge_id'], 'edge_cells_health_idx'));
        Schema::table('cache_purges', fn (Blueprint $table) => $table->index(['status', 'created_at'], 'cache_purges_health_idx'));
        Schema::table('usage_rollups', fn (Blueprint $table) => $table->index(['status', 'interval_end'], 'usage_rollups_health_idx'));
        Schema::table('backups', fn (Blueprint $table) => $table->index(['status', 'verified_at'], 'backups_health_idx'));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS domain_edge_placements_health_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS edge_cells_health_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS cache_purges_health_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS usage_rollups_health_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS backups_health_idx');

            return;
        }

        Schema::table('domain_edge_placements', fn (Blueprint $table) => $table->dropIndex('domain_edge_placements_health_idx'));
        Schema::table('edge_cells', fn (Blueprint $table) => $table->dropIndex('edge_cells_health_idx'));
        Schema::table('cache_purges', fn (Blueprint $table) => $table->dropIndex('cache_purges_health_idx'));
        Schema::table('usage_rollups', fn (Blueprint $table) => $table->dropIndex('usage_rollups_health_idx'));
        Schema::table('backups', fn (Blueprint $table) => $table->dropIndex('backups_health_idx'));
    }
};
