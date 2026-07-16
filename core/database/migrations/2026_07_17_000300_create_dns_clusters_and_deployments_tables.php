<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_clusters', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100)->unique();
            $table->string('location', 100);
            $table->boolean('enabled')->default(true);
            $table->string('api_url', 500);
            $table->text('api_key');
            $table->string('server_id', 100)->default('localhost');
            $table->jsonb('nameservers')->default('[]');
            $table->unsignedInteger('capacity_zones')->default(100000);
            $table->text('operational_notes')->nullable();
            $table->string('last_health_status', 20)->nullable();
            $table->text('last_health_error')->nullable();
            $table->timestampTz('last_health_at')->nullable();
            $table->unsignedBigInteger('last_reconciled_revision')->default(0);
            $table->timestampsTz();
            $table->index(['enabled', 'id']);
        });

        Schema::create('dns_deployments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_cluster_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('desired_revision')->default(0);
            $table->unsignedBigInteger('deployed_revision')->default(0);
            $table->string('status', 20)->default('pending');
            $table->char('active_checksum', 64)->nullable();
            $table->jsonb('active_rrsets')->default('[]');
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('deployed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['domain_id', 'dns_cluster_id']);
            $table->index(['status', 'updated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE dns_deployments ADD CONSTRAINT dns_deployments_status_check CHECK (status IN ('pending', 'deploying', 'succeeded', 'failed', 'obsolete'))");
            DB::statement("ALTER TABLE dns_clusters ADD CONSTRAINT dns_clusters_health_check CHECK (last_health_status IS NULL OR last_health_status IN ('healthy', 'unhealthy'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_deployments');
        Schema::dropIfExists('dns_clusters');
    }
};
