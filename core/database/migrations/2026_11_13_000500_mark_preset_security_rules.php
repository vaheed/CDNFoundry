<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: the rules written by the user agent and hotlink presets carry their preset, so the preset can be read back and replaced as a unit. */
    public function up(): void
    {
        Schema::table('security_rules', function (Blueprint $table): void {
            $table->string('preset', 20)->nullable();
            $table->index(['domain_id', 'preset']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE security_rules ADD CONSTRAINT security_rules_preset_known CHECK (preset IS NULL OR preset IN ('user_agent', 'hotlink'))");
        }
    }

    public function down(): void
    {
        Schema::table('security_rules', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE security_rules DROP CONSTRAINT IF EXISTS security_rules_preset_known');
            }
            $table->dropIndex(['domain_id', 'preset']);
            $table->dropColumn('preset');
        });
    }
};
