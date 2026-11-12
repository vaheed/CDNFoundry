<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's active incident profile, with its expiry. History lives in the audit log, so no row grows per incident. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->timestamp('incident_started_at')->nullable();
            $table->timestamp('incident_expires_at')->nullable();
            $table->foreignId('incident_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('incident_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropIndex(['incident_expires_at']);
            $table->dropConstrainedForeignId('incident_started_by');
            $table->dropColumn(['incident_started_at', 'incident_expires_at']);
        });
    }
};
