<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: why an order failed. A null class means platform, so orders that failed before this release keep
     * counting as platform faults and are never silently moved to customer health.
     */
    public function up(): void
    {
        Schema::table('tls_orders', function (Blueprint $table): void {
            $table->string('failure_class', 20)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tls_orders ADD CONSTRAINT tls_orders_failure_class_check CHECK (failure_class IS NULL OR failure_class IN ('platform', 'customer'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Expand-only migration: failure_class stays until a contract release removes it.');
    }
};
