<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: the platform zone now goes to the hidden DNS primary, so nothing writes cluster targets any more.
     * The column becomes optional and is dropped by a later contract migration once no running release reads it.
     */
    public function up(): void
    {
        Schema::table('platform_dns_settings', function (Blueprint $table): void {
            $table->jsonb('cluster_targets')->nullable()->default('[]')->change();
        });
    }

    public function down(): void
    {
        DB::table('platform_dns_settings')->whereNull('cluster_targets')->update(['cluster_targets' => '[]']);
        Schema::table('platform_dns_settings', function (Blueprint $table): void {
            $table->jsonb('cluster_targets')->nullable(false)->default(null)->change();
        });
    }
};
