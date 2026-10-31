<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edges', function (Blueprint $table): void {
            $table->timestampTz('bootstrap_expires_at')->nullable();
        });

        // A bootstrap token that was never used stops working 24 hours after this migration.
        DB::table('edges')->whereNotNull('bootstrap_token_hash')->whereNull('bootstrap_consumed_at')->update(['bootstrap_expires_at' => now()->addDay()]);
    }

    public function down(): void
    {
        Schema::table('edges', fn (Blueprint $table) => $table->dropColumn('bootstrap_expires_at'));
    }
};
