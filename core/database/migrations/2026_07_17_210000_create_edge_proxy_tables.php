<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->json('proxy_settings')->nullable();
            $table->unsignedBigInteger('active_edge_revision')->nullable();
        });
        Schema::table('dns_records', function (Blueprint $table): void {
            $table->json('origin')->nullable();
            $table->json('origin_health')->nullable();
        });

        Schema::create('edge_pools', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100)->unique();
            $table->string('kind', 16)->default('shared');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
        });
        Schema::create('edges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->char('country_code', 2);
            $table->char('continent_code', 2);
            $table->ipAddress('ipv4');
            $table->ipAddress('ipv6')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('drained')->default(false);
            $table->char('bootstrap_token_hash', 64)->nullable();
            $table->char('identity_hash', 64)->nullable()->unique();
            $table->timestampTz('identity_revoked_at')->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->string('agent_version', 40)->nullable();
            $table->json('capacity')->nullable();
            $table->unsignedBigInteger('active_sequence')->default(0);
            $table->timestampsTz();
            $table->index(['enabled', 'drained', 'last_heartbeat_at']);
        });
        Schema::create('edge_cells', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('edge_id')->constrained('edges')->cascadeOnDelete();
            $table->foreignId('edge_pool_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('drained')->default(false);
            $table->string('status', 20)->default('pending');
            $table->json('capacity')->nullable();
            $table->timestampsTz();
            $table->unique(['edge_id', 'name']);
            $table->unique(['edge_id', 'edge_pool_id']);
        });
        Schema::create('domain_edge_placements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_pool_id')->nullable()->constrained('edge_pools')->restrictOnDelete();
            $table->foreignId('target_pool_id')->nullable()->constrained('edge_pools')->restrictOnDelete();
            $table->string('state', 24)->default('pending');
            $table->unsignedBigInteger('desired_revision');
            $table->timestampTz('drain_after')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->unique('domain_id');
        });
        Schema::create('edge_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('revision');
            $table->json('snapshot');
            $table->char('checksum', 64);
            $table->string('status', 20)->default('validated');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['domain_id', 'revision']);
        });
        Schema::create('edge_artifacts', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->foreignUuid('edge_id')->constrained('edges')->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('revision');
            $table->json('payload');
            $table->char('checksum', 64);
            $table->char('signature', 64);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('minimum_agent_version', 20)->default('1.0.0');
            $table->string('maximum_agent_version', 20)->default('1.99.99');
            $table->timestampsTz();
            $table->unique(['edge_id', 'domain_id', 'revision', 'kind']);
            $table->index(['edge_id', 'sequence']);
        });
        Schema::create('edge_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('edge_id')->constrained('edges')->cascadeOnDelete();
            $table->string('type', 30);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['edge_id', 'status', 'created_at']);
        });
        $now = now();
        DB::table('edge_pools')->insert([
            ['name' => 'shared-default', 'kind' => 'shared', 'enabled' => true, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'quarantine-default', 'kind' => 'quarantine', 'enabled' => true, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_mode_check');
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_geo_config_check');
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_mode_check CHECK (mode IN ('dns_only', 'geo_dns', 'proxied'))");
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_mode_payload_check CHECK ((mode = 'dns_only' AND geo_config IS NULL AND origin IS NULL) OR (mode = 'geo_dns' AND type IN ('A', 'AAAA') AND geo_config IS NOT NULL AND origin IS NULL) OR (mode = 'proxied' AND type IN ('A', 'AAAA', 'CNAME') AND geo_config IS NULL AND origin IS NOT NULL))");
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_kind_check CHECK (kind IN ('shared', 'quarantine', 'dedicated'))");
            DB::statement("ALTER TABLE domain_edge_placements ADD CONSTRAINT placement_state_check CHECK (state IN ('pending', 'deploying', 'draining', 'active', 'failed'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_mode_payload_check');
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_mode_check');
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_mode_check CHECK (mode IN ('dns_only', 'geo_dns'))");
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_geo_config_check CHECK ((mode = 'dns_only' AND geo_config IS NULL) OR (mode = 'geo_dns' AND type IN ('A', 'AAAA') AND geo_config IS NOT NULL))");
        }
        Schema::dropIfExists('edge_tasks');
        Schema::dropIfExists('edge_artifacts');
        Schema::dropIfExists('edge_revisions');
        Schema::dropIfExists('domain_edge_placements');
        Schema::dropIfExists('edge_cells');
        Schema::dropIfExists('edges');
        Schema::dropIfExists('edge_pools');
        Schema::table('dns_records', fn (Blueprint $table) => $table->dropColumn(['origin', 'origin_health']));
        Schema::table('domains', fn (Blueprint $table) => $table->dropColumn(['proxy_settings', 'active_edge_revision']));
    }
};
