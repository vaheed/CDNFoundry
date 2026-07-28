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
            $table->string('cache_profile', 16)->default('standard')->after('kind');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_cache_profile_check CHECK (cache_profile IN ('small', 'standard', 'large', 'streaming'))");
        }
    }

    public function down(): void
    {
        Schema::table('edge_pools', fn (Blueprint $table) => $table->dropColumn('cache_profile'));
    }
};
