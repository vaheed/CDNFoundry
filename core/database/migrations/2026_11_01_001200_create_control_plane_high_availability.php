<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row. `epoch` counts promotions: it only ever grows, so a node that comes back after a
        // promotion can tell, and so can an edge, that it is no longer the active control plane.
        Schema::create('control_plane_state', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedBigInteger('epoch')->default(1);
            $table->string('active_node', 63)->nullable();
            // Ordered control addresses (at most two) that every edge dials; empty means one node.
            $table->json('addresses');
            $table->timestampsTz();
        });
        DB::table('control_plane_state')->insert(['id' => 1, 'epoch' => 1, 'addresses' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        // What each node reports about itself. Never a secret value, only a fingerprint of it.
        Schema::create('control_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 63)->unique();
            $table->string('role', 12);
            $table->string('address', 45);
            $table->json('fingerprints')->nullable();
            $table->unsignedInteger('replication_lag_seconds')->nullable();
            // Whether the Valkey replica is linked to the active node's Valkey.
            $table->boolean('valkey_linked')->nullable();
            $table->timestampTz('reported_at')->nullable();
            $table->timestampsTz();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE control_plane_state ADD CONSTRAINT control_plane_state_single_row CHECK (id = 1 AND epoch >= 1)');
            DB::statement("ALTER TABLE control_nodes ADD CONSTRAINT control_nodes_role_check CHECK (role IN ('active', 'standby'))");
            // At most one node of each role: two active nodes is the split brain this table must refuse.
            DB::statement('CREATE UNIQUE INDEX control_nodes_one_per_role ON control_nodes (role)');
        } else {
            Schema::table('control_nodes', fn (Blueprint $table) => $table->unique('role'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('control_nodes');
        Schema::dropIfExists('control_plane_state');
    }
};
