<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_probe_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('observer_edge_id');
            $table->uuid('target_edge_id');
            $table->ipAddress('address');
            $table->boolean('ok');
            $table->string('detail', 120)->nullable();
            $table->timestampTz('checked_at');
            $table->foreign('observer_edge_id')->references('id')->on('edges')->cascadeOnDelete();
            $table->foreign('target_edge_id')->references('id')->on('edges')->cascadeOnDelete();
            $table->unique(['observer_edge_id', 'target_edge_id', 'address']);
            $table->index(['target_edge_id', 'checked_at']);
        });

        Schema::table('edge_pool_endpoints', function (Blueprint $table): void {
            // What DNS publishes for this endpoint. It follows positive signals
            // only and survives missing heartbeats and control-plane faults.
            $table->boolean('dns_published')->default(false);
            $table->string('dns_reason', 80)->nullable();
            $table->timestampTz('dns_state_changed_at')->nullable();
        });

        Schema::table('edge_pools', function (Blueprint $table): void {
            $table->boolean('dns_fail_static')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('edge_pools', fn (Blueprint $table) => $table->dropColumn('dns_fail_static'));
        Schema::table('edge_pool_endpoints', fn (Blueprint $table) => $table->dropColumn(['dns_published', 'dns_reason', 'dns_state_changed_at']));
        Schema::dropIfExists('edge_probe_results');
    }
};
