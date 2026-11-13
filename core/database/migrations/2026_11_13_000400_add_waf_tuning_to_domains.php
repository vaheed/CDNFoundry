<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's adjustments to the managed rules (paranoia level, threshold, disabled categories and rules). Null means the profile's defaults. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('waf_tuning')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_waf_tuning_object CHECK (waf_tuning IS NULL OR jsonb_typeof(waf_tuning) = 'object')");
        }
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_waf_tuning_object');
            }
            $table->dropColumn('waf_tuning');
        });
    }
};
