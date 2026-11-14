<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's opt-in to client-side security and the scripts and connections its pages were seen to load. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('client_security')->nullable();
        });
        Schema::create('client_script_sightings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('page_path', 200);
            $table->string('resource', 200);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('approved_at')->nullable();
            $table->string('source', 16)->default('reported');
            $table->timestampsTz();
            $table->unique(['domain_id', 'kind', 'page_path', 'resource']);
            $table->index(['domain_id', 'first_seen_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_client_security_object CHECK (client_security IS NULL OR jsonb_typeof(client_security) = 'object')");
            DB::statement("ALTER TABLE client_script_sightings ADD CONSTRAINT client_script_sightings_kind CHECK (kind IN ('script', 'connection'))");
            DB::statement("ALTER TABLE client_script_sightings ADD CONSTRAINT client_script_sightings_source CHECK (source IN ('reported', 'baseline', 'approved'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_script_sightings');
        Schema::table('domains', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_client_security_object');
            }
            $table->dropColumn('client_security');
        });
    }
};
