<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attempt counters increment on every job attempt and are never reset while work keeps failing.
     * A smallint counter overflows after 32767 attempts and fails the very update that records the failure.
     */
    private const TABLES = [
        'operations',
        'edge_tasks',
        'tls_orders',
        'dns_deployments',
        'platform_dns_deployments',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedInteger('attempts')->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedSmallInteger('attempts')->default(0)->change();
            });
        }
    }
};
