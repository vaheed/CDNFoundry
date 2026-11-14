<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's compiled API schema table. Null means the API profile is off. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('api_schema')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_api_schema_object CHECK (api_schema IS NULL OR jsonb_typeof(api_schema) = 'object')");
        }
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_api_schema_object');
            }
            $table->dropColumn('api_schema');
        });
    }
};
