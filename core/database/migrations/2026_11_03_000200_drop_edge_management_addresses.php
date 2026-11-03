<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet owns host addresses now; the control plane never needed them to serve traffic. The origin safety guard still
 * blocks pool endpoints, anycast addresses, platform node addresses, and the operator's blocked list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edges', function (Blueprint $table): void {
            $table->dropColumn(['management_ipv4', 'management_ipv6']);
        });
    }

    public function down(): void
    {
        Schema::table('edges', function (Blueprint $table): void {
            $table->ipAddress('management_ipv4')->nullable();
            $table->ipAddress('management_ipv6')->nullable();
        });
    }
};
