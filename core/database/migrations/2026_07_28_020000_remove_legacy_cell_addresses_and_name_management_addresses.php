<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edges', function (Blueprint $table): void {
            $table->renameColumn('ipv4', 'management_ipv4');
            $table->renameColumn('ipv6', 'management_ipv6');
        });
        Schema::table('edges', function (Blueprint $table): void {
            $table->ipAddress('management_ipv4')->nullable()->change();
        });
        Schema::table('edge_cells', function (Blueprint $table): void {
            $table->dropUnique(['service_ipv4']);
            $table->dropUnique(['service_ipv6']);
            $table->dropColumn(['service_ipv4', 'service_ipv6']);
        });
    }

    public function down(): void
    {
        Schema::table('edge_cells', function (Blueprint $table): void {
            $table->ipAddress('service_ipv4')->nullable()->unique();
            $table->ipAddress('service_ipv6')->nullable()->unique();
        });
        Schema::table('edges', function (Blueprint $table): void {
            $table->renameColumn('management_ipv4', 'ipv4');
            $table->renameColumn('management_ipv6', 'ipv6');
        });
    }
};
