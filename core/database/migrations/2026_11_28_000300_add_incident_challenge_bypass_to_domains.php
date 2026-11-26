<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: what an incident profile lets through without the browser challenge, chosen when it is started. A null column means
     * the platform default (the API and certificate-validation prefixes, and no method).
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $json = DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json';
            $table->{$json}('incident_bypass_prefixes')->nullable();
            $table->{$json}('incident_bypass_methods')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_incident_bypass_prefixes_array CHECK (incident_bypass_prefixes IS NULL OR (jsonb_typeof(incident_bypass_prefixes) = 'array' AND jsonb_array_length(incident_bypass_prefixes) <= 8))");
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_incident_bypass_methods_array CHECK (incident_bypass_methods IS NULL OR (jsonb_typeof(incident_bypass_methods) = 'array' AND jsonb_array_length(incident_bypass_methods) <= 7))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Expand-only migration: the incident bypass columns stay until a contract release removes them.');
    }
};
