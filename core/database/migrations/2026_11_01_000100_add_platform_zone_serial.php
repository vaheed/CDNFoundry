<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_dns_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('soa_serial')->default(0);
            $table->string('zone_content_checksum', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_dns_settings', function (Blueprint $table): void {
            $table->dropColumn(['soa_serial', 'zone_content_checksum']);
        });
    }
};
