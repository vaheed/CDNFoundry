<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Platform rulesets: administrator-defined custom and rate-limit rules applied to every domain or to a chosen list, before the domain's own rules. */
    public function up(): void
    {
        $json = DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json';
        Schema::create('platform_rulesets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 250)->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('applies_to_all')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('platform_ruleset_rules', function (Blueprint $table) use ($json): void {
            $table->id();
            $table->foreignId('platform_ruleset_id')->constrained('platform_rulesets')->cascadeOnDelete();
            $table->string('name', 100)->nullable();
            $table->{$json}('conditions')->default('[]');
            $table->{$json}('rate_limit')->nullable();
            $table->string('action', 16);
            $table->integer('priority');
            $table->boolean('enabled')->default(true);
            $table->string('note', 250)->nullable();
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->unique(['platform_ruleset_id', 'fingerprint']);
            $table->index(['platform_ruleset_id', 'enabled', 'priority', 'id']);
        });
        Schema::create('platform_ruleset_domains', function (Blueprint $table): void {
            $table->foreignId('platform_ruleset_id')->constrained('platform_rulesets')->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->primary(['platform_ruleset_id', 'domain_id']);
            $table->index('domain_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE platform_ruleset_rules ADD CONSTRAINT platform_ruleset_rules_conditions_bounded CHECK (jsonb_typeof(conditions) = 'array' AND jsonb_array_length(conditions) <= 5)");
            DB::statement('ALTER TABLE platform_ruleset_rules ADD CONSTRAINT platform_ruleset_rules_conditions_required CHECK (jsonb_array_length(conditions) >= 1 OR rate_limit IS NOT NULL)');
            DB::statement("ALTER TABLE platform_ruleset_rules ADD CONSTRAINT platform_ruleset_rules_action_known CHECK (action IN ('block', 'challenge', 'log', 'allow', 'skip_managed'))");
            DB::statement("ALTER TABLE platform_ruleset_rules ADD CONSTRAINT platform_ruleset_rules_rate_limit_shape CHECK (rate_limit IS NULL OR (jsonb_typeof(rate_limit) = 'object' AND action IN ('block', 'challenge', 'log')))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ruleset_domains');
        Schema::dropIfExists('platform_ruleset_rules');
        Schema::dropIfExists('platform_rulesets');
    }
};
