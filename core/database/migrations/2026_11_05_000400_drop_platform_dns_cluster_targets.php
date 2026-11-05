<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contract step of the hidden-primary change: the platform zone goes to the hidden DNS primary,
     * and no release reads or writes cluster targets any more.
     */
    public function up(): void
    {
        if (Schema::hasColumn('platform_dns_settings', 'cluster_targets')) {
            Schema::table('platform_dns_settings', fn (Blueprint $table) => $table->dropColumn('cluster_targets'));
        }
    }

    public function down(): void
    {
        Schema::table('platform_dns_settings', function (Blueprint $table): void {
            $table->jsonb('cluster_targets')->nullable()->default('[]');
        });
    }
};
