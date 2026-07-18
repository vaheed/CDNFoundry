<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->softDeletesTz();
            $table->dropUnique(['name']);
        });
        DB::statement('CREATE UNIQUE INDEX domains_name_active_unique ON domains (name) WHERE deleted_at IS NULL');

        Schema::create('domain_name_tombstones', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 253)->unique();
            $table->unsignedBigInteger('source_domain_id');
            $table->timestampTz('deprovisioned_at');
            $table->timestampTz('reclaim_after');
            $table->timestampsTz();
            $table->index('reclaim_after');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_name_tombstones');
        DB::statement('DROP INDEX IF EXISTS domains_name_active_unique');
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropSoftDeletesTz();
            $table->unique('name');
        });
    }
};
