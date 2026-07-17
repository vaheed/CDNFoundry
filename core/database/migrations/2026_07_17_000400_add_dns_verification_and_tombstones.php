<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_deployments', function (Blueprint $table): void {
            $table->boolean('tombstone')->default(false);
            $table->timestampTz('deprovisioned_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dns_deployments DROP CONSTRAINT dns_deployments_status_check');
            DB::statement("ALTER TABLE dns_deployments ADD CONSTRAINT dns_deployments_status_check CHECK (status IN ('pending', 'deploying', 'succeeded', 'failed', 'obsolete', 'deprovisioning', 'deprovisioned'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dns_deployments DROP CONSTRAINT dns_deployments_status_check');
            DB::statement("ALTER TABLE dns_deployments ADD CONSTRAINT dns_deployments_status_check CHECK (status IN ('pending', 'deploying', 'succeeded', 'failed', 'obsolete'))");
        }
        Schema::table('dns_deployments', function (Blueprint $table): void {
            $table->dropColumn(['tombstone', 'deprovisioned_at']);
        });
    }
};
