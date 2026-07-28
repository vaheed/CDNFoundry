<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_pool_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->uuid('edge_id');
            $table->foreignId('edge_pool_id');
            $table->ipAddress('ipv4')->nullable();
            $table->ipAddress('ipv6')->nullable();
            $table->boolean('withdrawn')->default(false);
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('gateway_state', 20)->default('pending');
            $table->unsignedBigInteger('gateway_revision')->default(0);
            $table->string('readiness_reason', 80)->default('gateway_not_acknowledged');
            $table->timestampTz('gateway_acknowledged_at')->nullable();
            $table->timestampsTz();
            $table->foreign('edge_id')->references('id')->on('edges')->cascadeOnDelete();
            $table->foreign('edge_pool_id')->references('id')->on('edge_pools')->cascadeOnDelete();
            $table->unique(['edge_id', 'edge_pool_id']);
            $table->unique('ipv4');
            $table->unique('ipv6');
            $table->index(['edge_pool_id', 'withdrawn', 'gateway_state']);
        });

        DB::table('edge_cells')->whereNotNull('edge_pool_id')->orderBy('id')->get()
            ->groupBy(fn ($cell): string => $cell->edge_id.'|'.$cell->edge_pool_id)
            ->each(function ($cells): void {
                $first = $cells->first();
                $ipv4 = $cells->pluck('service_ipv4')->filter()->first();
                $ipv6 = $cells->pluck('service_ipv6')->filter()->first();
                if ($ipv4 === null && $ipv6 === null) {
                    return;
                }
                DB::table('edge_pool_endpoints')->insert([
                    'edge_id' => $first->edge_id, 'edge_pool_id' => $first->edge_pool_id,
                    'ipv4' => $ipv4, 'ipv6' => $ipv6,
                    'gateway_state' => 'pending', 'readiness_reason' => 'gateway_not_acknowledged',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE edge_pool_endpoints ADD CONSTRAINT edge_pool_endpoints_gateway_state_check CHECK (gateway_state IN ('pending', 'ready', 'degraded', 'failed'))");
            DB::statement('ALTER TABLE edge_pool_endpoints ADD CONSTRAINT edge_pool_endpoints_address_check CHECK (ipv4 IS NOT NULL OR ipv6 IS NOT NULL) NOT VALID');
            DB::statement('ALTER TABLE edge_pool_endpoints VALIDATE CONSTRAINT edge_pool_endpoints_address_check');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_pool_endpoints');
    }
};
