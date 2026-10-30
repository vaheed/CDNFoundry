<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edges', function (Blueprint $table): void {
            // A renewed certificate waits here until the edge proves it by
            // authenticating with it. Until then both certificates are accepted.
            $table->text('pending_identity_certificate')->nullable();
            $table->string('pending_identity_certificate_serial', 64)->nullable()->unique();
            $table->timestampTz('pending_identity_certificate_expires_at')->nullable();
            $table->string('pending_identity_csr_hash', 64)->nullable();
            // Expiry of the certificate the agent is actually using, from its heartbeat.
            $table->timestampTz('reported_identity_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edges', fn (Blueprint $table) => $table->dropColumn([
            'pending_identity_certificate', 'pending_identity_certificate_serial', 'pending_identity_certificate_expires_at',
            'pending_identity_csr_hash', 'reported_identity_expires_at',
        ]));
    }
};
