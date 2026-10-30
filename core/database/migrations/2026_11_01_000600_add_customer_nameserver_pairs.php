<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_dns_settings', function (Blueprint $table): void {
            $table->jsonb('customer_nameserver_pairs')->nullable();
        });

        // One-time seed: the pool starts as the platform nameservers taken two at a time, which is
        // what customers were already delegated to. Administrators edit the pool from then on.
        foreach (DB::table('platform_dns_settings')->get() as $row) {
            $pairs = array_values(array_filter(array_chunk(json_decode($row->nameservers, true) ?? [], 2), fn (array $pair): bool => count($pair) === 2));
            DB::table('platform_dns_settings')->where('id', $row->id)->update(['customer_nameserver_pairs' => json_encode($pairs)]);
        }

        // A pair is reserved by one live claim per name; the same pair may serve many names.
        DB::statement("CREATE UNIQUE INDEX domains_name_pair_reserved ON domains (name, delegation_nameservers) WHERE deleted_at IS NULL AND delegation_nameservers IS NOT NULL AND lifecycle_state <> 'deprovisioning'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS domains_name_pair_reserved');
        Schema::table('platform_dns_settings', fn (Blueprint $table) => $table->dropColumn('customer_nameserver_pairs'));
    }
};
