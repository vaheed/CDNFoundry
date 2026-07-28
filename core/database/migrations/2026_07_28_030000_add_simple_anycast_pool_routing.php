<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_pools', function (Blueprint $table): void {
            $table->string('routing_mode', 24)->default('geo_unicast')->after('kind');
            $table->ipAddress('anycast_ipv4')->nullable()->after('routing_mode');
            $table->ipAddress('anycast_ipv6')->nullable()->after('anycast_ipv4');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_routing_mode_check CHECK (routing_mode IN ('geo_unicast', 'simple_anycast'))");
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_anycast_addresses_check CHECK ((routing_mode = 'geo_unicast' AND anycast_ipv4 IS NULL AND anycast_ipv6 IS NULL) OR (routing_mode = 'simple_anycast' AND (anycast_ipv4 IS NOT NULL OR anycast_ipv6 IS NOT NULL))) NOT VALID");
            DB::statement('ALTER TABLE edge_pools VALIDATE CONSTRAINT edge_pools_anycast_addresses_check');
            DB::statement('ALTER TABLE edge_pool_endpoints DROP CONSTRAINT edge_pool_endpoints_address_check');
        }

        Schema::table('edge_pools', function (Blueprint $table): void {
            $table->unique('anycast_ipv4');
            $table->unique('anycast_ipv6');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE edge_pools DROP CONSTRAINT edge_pools_routing_mode_check');
            DB::statement('ALTER TABLE edge_pools DROP CONSTRAINT edge_pools_anycast_addresses_check');
        }
        Schema::table('edge_pools', function (Blueprint $table): void {
            $table->dropUnique(['anycast_ipv4']);
            $table->dropUnique(['anycast_ipv6']);
            $table->dropColumn(['routing_mode', 'anycast_ipv4', 'anycast_ipv6']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE edge_pool_endpoints ADD CONSTRAINT edge_pool_endpoints_address_check CHECK (ipv4 IS NOT NULL OR ipv6 IS NOT NULL) NOT VALID');
            DB::statement('ALTER TABLE edge_pool_endpoints VALIDATE CONSTRAINT edge_pool_endpoints_address_check');
        }
    }
};
