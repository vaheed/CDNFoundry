<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_pools', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minimum_ready_cells')->default(1);
            $table->unsignedSmallInteger('replicas_per_edge')->default(1);
            $table->unsignedInteger('maximum_domains_per_cell')->default(20000);
        });
        Schema::table('edge_cells', fn (Blueprint $table) => $table->dropUnique(['edge_id', 'edge_pool_id']));

        Schema::create('domain_edge_cells', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('edge_id')->constrained('edges')->cascadeOnDelete();
            $table->unsignedTinyInteger('replica')->default(1);
            $table->foreignId('active_cell_id')->nullable()->constrained('edge_cells')->restrictOnDelete();
            $table->foreignId('target_cell_id')->nullable()->constrained('edge_cells')->restrictOnDelete();
            $table->unsignedBigInteger('desired_revision');
            $table->string('state', 24)->default('deploying');
            $table->timestampTz('drain_after')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['domain_id', 'edge_id', 'replica']);
            $table->index(['edge_id', 'target_cell_id', 'state']);
            $table->index(['active_cell_id', 'state']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE edge_pools DROP CONSTRAINT IF EXISTS edge_pools_kind_check');
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_kind_check CHECK (kind IN ('shared', 'reserved', 'dedicated', 'quarantine'))");
            DB::statement('ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_minimum_ready_check CHECK (minimum_ready_cells BETWEEN 1 AND 32)');
            DB::statement('ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_replicas_check CHECK (replicas_per_edge BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_capacity_check CHECK (maximum_domains_per_cell BETWEEN 1 AND 100000)');
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_replica_kind_check CHECK (replicas_per_edge = 1 OR kind IN ('reserved', 'dedicated'))");
            DB::statement("ALTER TABLE domain_edge_cells ADD CONSTRAINT domain_edge_cells_state_check CHECK (state IN ('deploying', 'draining', 'active', 'failed'))");
            DB::statement('ALTER TABLE domain_edge_cells ADD CONSTRAINT domain_edge_cells_target_check CHECK (active_cell_id IS NOT NULL OR target_cell_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_edge_cells');
        if (DB::getDriverName() === 'pgsql') {
            foreach (['edge_pools_replica_kind_check', 'edge_pools_capacity_check', 'edge_pools_replicas_check', 'edge_pools_minimum_ready_check'] as $constraint) {
                DB::statement("ALTER TABLE edge_pools DROP CONSTRAINT IF EXISTS {$constraint}");
            }
            DB::statement('ALTER TABLE edge_pools DROP CONSTRAINT IF EXISTS edge_pools_kind_check');
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_kind_check CHECK (kind IN ('shared', 'quarantine', 'dedicated'))");
        }
        Schema::table('edge_pools', fn (Blueprint $table) => $table->dropColumn(['minimum_ready_cells', 'replicas_per_edge', 'maximum_domains_per_cell']));
    }
};
