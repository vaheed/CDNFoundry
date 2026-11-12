<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's active incident profile, with its expiry. History lives in the audit log, so no row grows per incident. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->timestamp('incident_started_at')->nullable();
            $table->timestamp('incident_expires_at')->nullable();
            $table->unsignedBigInteger('incident_started_by')->nullable();
            $table->index('incident_expires_at');
        });
        // SQLite rebuilds the whole table to add a foreign key and would drop the domain name's partial unique index, so only PostgreSQL gets the constraint.
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('domains', fn (Blueprint $table) => $table->foreign('incident_started_by')->references('id')->on('users')->nullOnDelete());
        }
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropIndex(['incident_expires_at']);
            if (DB::getDriverName() === 'pgsql') {
                $table->dropForeign(['incident_started_by']);
            }
            $table->dropColumn(['incident_started_at', 'incident_expires_at', 'incident_started_by']);
        });
    }
};
