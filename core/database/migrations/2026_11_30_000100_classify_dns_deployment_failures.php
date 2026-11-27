<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: why a deployment failed and since when. A null class means platform, so rows written before this
     * release and by an older job keep counting as platform faults, never as customer problems.
     */
    public function up(): void
    {
        Schema::table('dns_deployments', function (Blueprint $table): void {
            $table->string('failure_class', 20)->nullable();
            $table->timestampTz('first_failed_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE dns_deployments ADD CONSTRAINT dns_deployments_failure_class_check CHECK (failure_class IS NULL OR failure_class IN ('platform', 'customer_content'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Expand-only migration: the failure columns stay until a contract release removes them.');
    }
};
