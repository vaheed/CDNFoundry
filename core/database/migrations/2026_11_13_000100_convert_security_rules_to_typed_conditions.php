<?php

use App\Support\SecurityRuleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = ['ip' => 'client_ip', 'cidr' => 'client_ip', 'country' => 'country', 'continent' => 'continent'];

    /**
     * Replaces the single match type and value of a security rule with up to five typed conditions. Every existing IP,
     * CIDR, country, and continent rule becomes one condition with the same meaning and keeps its id and priority.
     * This is a 2.0 breaking change: no old and new shape coexist, so the migration runs explicitly during an upgrade.
     */
    public function up(): void
    {
        Schema::table('security_rules', function (Blueprint $table): void {
            $table->string('name', 100)->nullable();
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('conditions')->default('[]');
            $table->string('fingerprint', 64)->nullable();
        });

        DB::table('security_rules')->orderBy('id')->each(function (object $rule): void {
            $conditions = [['field' => self::FIELDS[$rule->match_type], 'operator' => 'equals', 'value' => $rule->value]];
            DB::table('security_rules')->where('id', $rule->id)->update([
                'conditions' => json_encode($conditions, JSON_THROW_ON_ERROR),
                'fingerprint' => SecurityRuleData::fingerprint($conditions, $rule->action),
            ]);
        });

        Schema::table('security_rules', function (Blueprint $table): void {
            $table->dropUnique('security_rules_domain_id_match_type_value_action_unique');
            $table->dropColumn(['match_type', 'value']);
        });
        Schema::table('security_rules', function (Blueprint $table): void {
            $table->string('fingerprint', 64)->nullable(false)->change();
            $table->unique(['domain_id', 'fingerprint']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE security_rules ADD CONSTRAINT security_rules_conditions_bounded CHECK (jsonb_typeof(conditions) = 'array' AND jsonb_array_length(conditions) BETWEEN 1 AND 5)");
            DB::statement("ALTER TABLE security_rules ADD CONSTRAINT security_rules_action_known CHECK (action IN ('block', 'challenge', 'log', 'allow', 'skip_managed'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Typed security rules cannot be converted back to a single match type.');
    }
};
