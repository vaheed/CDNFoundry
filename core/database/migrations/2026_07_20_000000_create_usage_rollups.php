<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_rollups', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('interval_start');
            $table->timestampTz('interval_end');
            $table->string('granularity', 12)->default('hour');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->unsignedBigInteger('cache_hits')->default(0);
            $table->unsignedBigInteger('dns_queries')->default(0);
            $table->string('status', 16)->default('finalized');
            $table->timestampTz('source_finalized_at')->nullable();
            $table->timestampsTz();
            $table->unique(['domain_id', 'interval_start', 'granularity'], 'usage_rollups_interval_unique');
            $table->index(['interval_start', 'domain_id']);
        });
        DB::table('system_settings')->insertOrIgnore([
            'group' => 'telemetry',
            'values' => json_encode(['raw_retention_days' => 7, 'hourly_retention_days' => 400, 'daily_retention_days' => 1095, 'finalization_delay_minutes' => 15, 'ipv4_mask_bits' => 24, 'ipv6_mask_bits' => 48], JSON_THROW_ON_ERROR),
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_granularity_check CHECK (granularity IN ('hour', 'day'))");
            DB::statement("ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_status_check CHECK (status IN ('provisional', 'finalized'))");
            DB::statement('ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_interval_check CHECK (interval_end > interval_start)');
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'telemetry')->delete();
        Schema::dropIfExists('usage_rollups');
    }
};
