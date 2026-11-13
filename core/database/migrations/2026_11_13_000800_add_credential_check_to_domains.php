<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's login paths and password fields for the leaked-credential check. Null means the check is off. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('credential_check')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_credential_check_object CHECK (credential_check IS NULL OR jsonb_typeof(credential_check) = 'object')");
        }
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_credential_check_object');
            }
            $table->dropColumn('credential_check');
        });
    }
};
