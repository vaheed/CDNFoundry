<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a rule may carry a typed rate limit (key, threshold, period, duration). Only a rate-limit rule may have no conditions. */
    public function up(): void
    {
        Schema::table('security_rules', function (Blueprint $table): void {
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('rate_limit')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE security_rules DROP CONSTRAINT security_rules_conditions_bounded');
            DB::statement("ALTER TABLE security_rules ADD CONSTRAINT security_rules_conditions_bounded CHECK (jsonb_typeof(conditions) = 'array' AND jsonb_array_length(conditions) <= 5)");
            DB::statement('ALTER TABLE security_rules ADD CONSTRAINT security_rules_conditions_required CHECK (jsonb_array_length(conditions) >= 1 OR rate_limit IS NOT NULL)');
            DB::statement("ALTER TABLE security_rules ADD CONSTRAINT security_rules_rate_limit_object CHECK (rate_limit IS NULL OR jsonb_typeof(rate_limit) = 'object')");
            DB::statement("ALTER TABLE security_rules ADD CONSTRAINT security_rules_rate_limit_action CHECK (rate_limit IS NULL OR action IN ('block', 'challenge', 'log'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rate-limit rules cannot be removed from the rules table once they exist.');
    }
};
