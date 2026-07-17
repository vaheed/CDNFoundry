<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_dns_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1);
        });

        Schema::create('platform_dns_deployments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('dns_cluster_id')->constrained()->cascadeOnDelete()->unique();
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
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_dns_deployments');
        Schema::table('platform_dns_settings', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
