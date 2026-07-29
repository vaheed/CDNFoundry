<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_releases', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100)->unique();
            $table->string('gateway_image', 255);
            $table->string('agent_image', 255);
            $table->string('normal_cell_image', 255);
            $table->string('waf_cell_image', 255);
            $table->string('minimum_compatible_version', 40);
            $table->string('maximum_compatible_version', 40);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
        });
        Schema::create('fleet_rollouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('fleet_release_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_release_id')->nullable()->constrained('fleet_releases')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('current_wave')->default(0);
            $table->unsignedSmallInteger('maximum_parallel')->default(2);
            $table->unsignedSmallInteger('minimum_ready_percent')->default(100);
            $table->unsignedSmallInteger('maximum_error_percent')->default(5);
            $table->unsignedInteger('mixed_version_window_minutes')->default(60);
            $table->string('pause_reason', 100)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
        Schema::create('fleet_rollout_edges', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('fleet_rollout_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('edge_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('wave');
            $table->string('status', 24)->default('pending');
            $table->json('previous_versions')->nullable();
            $table->json('desired_versions');
            $table->string('failure_reason', 100)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['fleet_rollout_id', 'edge_id']);
            $table->index(['fleet_rollout_id', 'wave', 'status']);
        });

        Schema::table('edges', function (Blueprint $table): void {
            $table->json('runtime_versions')->nullable();
            $table->json('desired_runtime_versions')->nullable();
            $table->timestampTz('runtime_versions_reported_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fleet_rollouts ADD CONSTRAINT fleet_rollouts_status_check CHECK (status IN ('pending','running','paused','rolling_back','succeeded','failed','rolled_back','cancelled'))");
            DB::statement("ALTER TABLE fleet_rollout_edges ADD CONSTRAINT fleet_rollout_edges_status_check CHECK (status IN ('pending','dispatched','succeeded','failed','rolling_back','rolled_back'))");
            DB::statement('ALTER TABLE fleet_rollouts ADD CONSTRAINT fleet_rollout_bounds_check CHECK (maximum_parallel BETWEEN 1 AND 20 AND minimum_ready_percent BETWEEN 50 AND 100 AND maximum_error_percent BETWEEN 0 AND 25 AND mixed_version_window_minutes BETWEEN 5 AND 1440)');
        }
    }

    public function down(): void
    {
        Schema::table('edges', fn (Blueprint $table) => $table->dropColumn(['runtime_versions', 'desired_runtime_versions', 'runtime_versions_reported_at']));
        Schema::dropIfExists('fleet_rollout_edges');
        Schema::dropIfExists('fleet_rollouts');
        Schema::dropIfExists('fleet_releases');
    }
};
