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
            $table->string('tls_mode', 16)->default('managed');
        });
        Schema::create('tls_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('status', 20)->default('pending');
            $table->text('certificate_pem');
            $table->text('chain_pem');
            $table->text('private_key_ciphertext');
            $table->json('names');
            $table->char('fingerprint_sha256', 64);
            $table->timestampTz('not_before');
            $table->timestampTz('expires_at');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['domain_id', 'kind', 'fingerprint_sha256']);
            $table->index(['status', 'expires_at']);
        });
        Schema::table('domains', function (Blueprint $table): void {
            // SQLite rebuilds the table when adding this foreign key and silently
            // strips the predicate from domains_name_active_unique. The isolated
            // test database still gets the typed column; PostgreSQL owns the
            // production referential-integrity constraint.
            $column = $table->foreignUuid('active_tls_certificate_id')->nullable();
            if (DB::getDriverName() === 'pgsql') {
                $column->constrained('tls_certificates')->nullOnDelete();
            }
        });
        Schema::create('tls_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('certificate_id')->nullable()->constrained('tls_certificates')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->json('names');
            $table->string('acme_order_url', 2048)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'available_at']);
            $table->index(['domain_id', 'created_at']);
        });
        Schema::create('acme_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tls_order_id')->constrained('tls_orders')->cascadeOnDelete();
            $table->string('hostname', 253);
            $table->string('record_name', 253);
            $table->text('record_value');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('cleaned_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'expires_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_tls_mode_check CHECK (tls_mode IN ('managed', 'custom', 'disabled'))");
            DB::statement("ALTER TABLE tls_certificates ADD CONSTRAINT tls_certificates_kind_check CHECK (kind IN ('managed', 'custom'))");
            DB::statement("ALTER TABLE tls_certificates ADD CONSTRAINT tls_certificates_status_check CHECK (status IN ('pending', 'active', 'superseded', 'failed', 'revoked'))");
            DB::statement("ALTER TABLE tls_orders ADD CONSTRAINT tls_orders_status_check CHECK (status IN ('pending', 'running', 'validating', 'succeeded', 'failed'))");
            DB::statement("ALTER TABLE acme_challenges ADD CONSTRAINT acme_challenges_status_check CHECK (status IN ('pending', 'published', 'validating', 'valid', 'failed', 'cleaned'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acme_challenges');
        Schema::dropIfExists('tls_orders');
        Schema::table('domains', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                $table->dropConstrainedForeignId('active_tls_certificate_id');
            } else {
                $table->dropColumn('active_tls_certificate_id');
            }
        });
        Schema::dropIfExists('tls_certificates');
        Schema::table('domains', fn (Blueprint $table) => $table->dropColumn('tls_mode'));
    }
};
