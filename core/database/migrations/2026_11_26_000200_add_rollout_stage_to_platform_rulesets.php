<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: a ruleset rolls out in stages. In `shadow` its blocking and challenging rules only record what they would
     * have done; in `subset` they act on the listed domains and record on the rest; in `all` they act everywhere. Existing
     * rulesets stay in `all`, so nothing changes for them.
     */
    public function up(): void
    {
        Schema::table('platform_rulesets', function (Blueprint $table): void {
            $table->string('rollout_stage', 8)->default('all');
        });
        Schema::create('platform_ruleset_enforced_domains', function (Blueprint $table): void {
            $table->foreignId('platform_ruleset_id')->constrained('platform_rulesets')->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->primary(['platform_ruleset_id', 'domain_id']);
            $table->index('domain_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE platform_rulesets ADD CONSTRAINT platform_rulesets_rollout_stage_known CHECK (rollout_stage IN ('shadow', 'subset', 'all'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('A ruleset rollout stage cannot be removed once it exists.');
    }
};
