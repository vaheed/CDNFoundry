<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step. Usage rows keep the name and the owners the domain had when the row was written, and no longer
     * disappear with the domain. A coverage table records which hours were built and whether the telemetry for them
     * was complete, so an hour with no row is a known zero only inside a complete hour. Existing rows are untouched;
     * the retained name is filled from the domain that still exists.
     */
    public function up(): void
    {
        Schema::table('usage_rollups', function (Blueprint $table): void {
            $table->string('domain_name', 253)->nullable();
            $table->jsonb('owner_user_ids')->nullable();
            $table->jsonb('corrections')->nullable();
        });
        DB::statement('UPDATE usage_rollups SET domain_name = (SELECT name FROM domains WHERE domains.id = usage_rollups.domain_id)');
        Schema::create('usage_coverage', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('interval_start')->unique();
            $table->timestampTz('interval_end');
            $table->string('status', 16);
            $table->string('reason', 64)->nullable();
            $table->unsignedInteger('domains_with_traffic')->default(0);
            $table->timestampTz('built_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usage_rollups DROP CONSTRAINT IF EXISTS usage_rollups_domain_id_foreign');
            DB::statement('ALTER TABLE usage_rollups DROP CONSTRAINT IF EXISTS usage_rollups_status_check');
            DB::statement("ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_status_check CHECK (status IN ('provisional', 'finalized', 'reconciled', 'corrected', 'incomplete'))");
            DB::statement("ALTER TABLE usage_coverage ADD CONSTRAINT usage_coverage_status_check CHECK (status IN ('finalized', 'incomplete'))");
            DB::statement('ALTER TABLE usage_coverage ADD CONSTRAINT usage_coverage_interval_check CHECK (interval_end > interval_start)');
            DB::statement("ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_owner_user_ids_check CHECK (owner_user_ids IS NULL OR jsonb_typeof(owner_user_ids) = 'array')");
            DB::statement("ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_corrections_check CHECK (corrections IS NULL OR jsonb_typeof(corrections) = 'array')");
        } else {
            Schema::table('usage_rollups', fn (Blueprint $table) => $table->dropForeign(['domain_id']));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Expand-only migration: usage attribution and coverage stay until a contract release.');
    }
};
