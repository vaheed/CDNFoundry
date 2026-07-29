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
            $table->string('compression_profile', 24)->default('standard')->after('cache_profile');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_compression_profile_check CHECK (compression_profile IN ('off', 'standard', 'maximum_savings'))");
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_compression_kind_check CHECK (compression_profile <> 'maximum_savings' OR kind IN ('reserved', 'dedicated'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE edge_pools DROP CONSTRAINT IF EXISTS edge_pools_compression_kind_check');
            DB::statement('ALTER TABLE edge_pools DROP CONSTRAINT IF EXISTS edge_pools_compression_profile_check');
        }
        Schema::table('edge_pools', fn (Blueprint $table) => $table->dropColumn('compression_profile'));
    }
};
