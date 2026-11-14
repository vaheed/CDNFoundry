<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's alert threshold for mitigated requests per period. Null means no alert. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('security_alert')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_security_alert_object CHECK (security_alert IS NULL OR jsonb_typeof(security_alert) = 'object')");
        }
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_security_alert_object');
            }
            $table->dropColumn('security_alert');
        });
    }
};
