<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_dns_deployments', function (Blueprint $table): void {
            $table->string('active_zone', 253)->nullable()->after('active_checksum');
        });
        DB::table('platform_dns_deployments')->whereNull('active_zone')->orderBy('id')->get(['id', 'active_rrsets'])
            ->each(function ($deployment): void {
                $rrsets = is_string($deployment->active_rrsets) ? json_decode($deployment->active_rrsets, true) : $deployment->active_rrsets;
                $soa = collect(is_array($rrsets) ? $rrsets : [])->firstWhere('type', 'SOA');
                if (is_array($soa) && filled($soa['name'] ?? null)) {
                    DB::table('platform_dns_deployments')->where('id', $deployment->id)->update(['active_zone' => rtrim($soa['name'], '.')]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('platform_dns_deployments', function (Blueprint $table): void {
            $table->dropColumn('active_zone');
        });
    }
};
