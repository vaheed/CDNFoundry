<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: origin health as separate signals per origin role and kind, each bound to the configuration it was
     * measured against. `origin_health` stays readable and is used while a record has no signals; a contract release
     * removes it after every record has been written once.
     */
    public function up(): void
    {
        Schema::table('dns_records', function (Blueprint $table): void {
            $table->jsonb('origin_signals')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_origin_signals_check CHECK (origin_signals IS NULL OR (jsonb_typeof(origin_signals) = 'object' AND mode = 'proxied'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Expand-only migration: origin_signals stays until a contract release removes origin_health.');
    }
};
