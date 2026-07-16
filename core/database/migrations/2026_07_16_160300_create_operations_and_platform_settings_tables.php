<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 100);
            $table->string('status', 20)->default('pending');
            $table->jsonb('input')->default('{}');
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampsTz();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->index(['status', 'created_at']);
        });

        Schema::create('platform_dns_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('platform_domain');
            $table->string('proxy_hostname');
            $table->jsonb('nameservers');
            $table->string('soa_primary');
            $table->string('soa_mailbox');
            $table->unsignedInteger('soa_refresh');
            $table->unsignedInteger('soa_retry');
            $table->unsignedInteger('soa_expire');
            $table->unsignedInteger('soa_minimum_ttl');
            $table->unsignedInteger('default_ttl');
            $table->jsonb('cluster_targets');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_dns_settings');
        Schema::dropIfExists('operations');
    }
};
