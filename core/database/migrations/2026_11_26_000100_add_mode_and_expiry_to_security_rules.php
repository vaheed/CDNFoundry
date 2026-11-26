<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['security_rules', 'platform_ruleset_rules'];

    /**
     * Expand step: a rule may run in shadow mode (evaluated and recorded, never acting) and may carry an expiry the cell enforces
     * by its own clock. Existing rules stay enforcing and never expire, so mixed-version readers see no change.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('mode', 8)->default('enforce');
                $table->timestampTz('expires_at')->nullable();
            });
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE $name ADD CONSTRAINT {$name}_mode_known CHECK (mode IN ('enforce', 'shadow'))");
                DB::statement("ALTER TABLE $name ADD CONSTRAINT {$name}_shadow_action CHECK (mode = 'enforce' OR action IN ('block', 'challenge'))");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Shadow and expiring rules cannot be removed once they exist.');
    }
};
