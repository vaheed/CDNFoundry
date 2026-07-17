<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_records', function (Blueprint $table): void {
            $table->jsonb('geo_config')->nullable()->after('mode');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_mode_check');
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_mode_check CHECK (mode IN ('dns_only', 'geo_dns'))");
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_geo_config_check CHECK ((mode = 'dns_only' AND geo_config IS NULL) OR (mode = 'geo_dns' AND type IN ('A', 'AAAA') AND geo_config IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_geo_config_check');
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_mode_check');
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_mode_check CHECK (mode = 'dns_only')");
        }
        Schema::table('dns_records', fn (Blueprint $table) => $table->dropColumn('geo_config'));
    }
};
