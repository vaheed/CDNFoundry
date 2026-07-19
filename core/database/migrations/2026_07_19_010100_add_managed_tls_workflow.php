<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acme_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('directory_url', 2048)->unique();
            $table->string('contact_email', 320);
            $table->text('private_key_ciphertext');
            $table->string('account_url', 2048)->nullable();
            $table->timestampsTz();
        });

        Schema::table('tls_orders', function (Blueprint $table): void {
            $table->foreignId('acme_account_id')->nullable()->after('domain_id')->constrained('acme_accounts')->nullOnDelete();
            $table->json('authorization_urls')->nullable();
            $table->string('finalize_url', 2048)->nullable();
            $table->string('certificate_url', 2048)->nullable();
            $table->text('private_key_ciphertext')->nullable();
            $table->text('csr_der')->nullable();
            $table->unsignedBigInteger('dns_revision')->nullable();
            $table->timestampTz('next_poll_at')->nullable();
            $table->timestampTz('alerted_at')->nullable();
            $table->char('names_hash', 64)->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX tls_orders_active_names_unique ON tls_orders (domain_id, names_hash) WHERE status IN ('pending', 'creating', 'publishing', 'validating', 'finalizing')");

        Schema::table('acme_challenges', function (Blueprint $table): void {
            $table->string('authorization_url', 2048)->nullable();
            $table->string('challenge_url', 2048)->nullable();
            $table->string('token', 512)->nullable();
        });

        Schema::table('tls_certificates', function (Blueprint $table): void {
            $table->timestampTz('alerted_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tls_orders DROP CONSTRAINT tls_orders_status_check');
            DB::statement("ALTER TABLE tls_orders ADD CONSTRAINT tls_orders_status_check CHECK (status IN ('pending', 'creating', 'publishing', 'validating', 'finalizing', 'succeeded', 'failed', 'obsolete'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tls_orders DROP CONSTRAINT tls_orders_status_check');
            DB::statement("ALTER TABLE tls_orders ADD CONSTRAINT tls_orders_status_check CHECK (status IN ('pending', 'running', 'validating', 'succeeded', 'failed'))");
        }
        Schema::table('tls_certificates', fn (Blueprint $table) => $table->dropColumn('alerted_at'));
        Schema::table('acme_challenges', fn (Blueprint $table) => $table->dropColumn(['authorization_url', 'challenge_url', 'token']));
        DB::statement('DROP INDEX IF EXISTS tls_orders_active_names_unique');
        Schema::table('tls_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acme_account_id');
            $table->dropColumn(['authorization_urls', 'finalize_url', 'certificate_url', 'private_key_ciphertext', 'csr_der', 'dns_revision', 'next_poll_at', 'alerted_at', 'names_hash']);
        });
        Schema::dropIfExists('acme_accounts');
    }
};
