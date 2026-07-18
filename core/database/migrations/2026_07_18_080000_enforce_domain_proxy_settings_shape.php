<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE domains SET proxy_settings = NULL WHERE proxy_settings IS NOT NULL AND jsonb_typeof(proxy_settings) <> 'object'");
        DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_proxy_settings_object CHECK (proxy_settings IS NULL OR jsonb_typeof(proxy_settings) = 'object') NOT VALID");
        DB::statement('ALTER TABLE domains VALIDATE CONSTRAINT domains_proxy_settings_object');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_proxy_settings_object');
        }
    }
};
