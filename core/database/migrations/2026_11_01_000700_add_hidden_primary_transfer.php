<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_clusters', function (Blueprint $table): void {
            // Where DNS nodes pull zones from (an IP literal and port: PowerDNS accepts no hostname),
            // and the TSIG secret that signs those transfers.
            $table->string('transfer_endpoint', 100)->nullable();
            $table->text('transfer_tsig_secret')->nullable();
        });

        // Laravel writes to one private primary, so at most one cluster may be enabled.
        DB::statement('CREATE UNIQUE INDEX dns_clusters_single_enabled ON dns_clusters ((1)) WHERE enabled');

        // One row per zone, replaced when the zone changes, so its id is a cursor that only moves
        // forward. DNS nodes read the rows after their cursor and pull exactly those zones.
        Schema::create('dns_zone_changes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('zone', 253)->unique();
            $table->unsignedBigInteger('serial')->default(0);
            $table->boolean('deleted')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zone_changes');
        DB::statement('DROP INDEX IF EXISTS dns_clusters_single_enabled');
        Schema::table('dns_clusters', fn (Blueprint $table) => $table->dropColumn(['transfer_endpoint', 'transfer_tsig_secret']));
    }
};
