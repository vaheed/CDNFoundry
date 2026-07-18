<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_mode_payload_check');
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_mode_payload_check CHECK ((mode = 'dns_only' AND geo_config IS NULL AND origin IS NULL) OR (mode = 'geo_dns' AND geo_config IS NOT NULL AND origin IS NULL) OR (mode = 'proxied' AND type IN ('A', 'AAAA', 'CNAME') AND geo_config IS NULL AND origin IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dns_records DROP CONSTRAINT dns_records_mode_payload_check');
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_mode_payload_check CHECK ((mode = 'dns_only' AND geo_config IS NULL AND origin IS NULL) OR (mode = 'geo_dns' AND type IN ('A', 'AAAA') AND geo_config IS NOT NULL AND origin IS NULL) OR (mode = 'proxied' AND type IN ('A', 'AAAA', 'CNAME') AND geo_config IS NULL AND origin IS NOT NULL))");
        }
    }
};
