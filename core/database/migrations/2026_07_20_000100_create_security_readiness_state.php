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
            $table->jsonb('security_settings')->nullable();
            $table->string('security_state', 20)->default('normal');
            $table->timestampTz('security_state_changed_at')->nullable();
        });
        Schema::create('security_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('match_type', 16);
            $table->string('value', 128);
            $table->string('action', 8);
            $table->integer('priority');
            $table->boolean('enabled')->default(true);
            $table->string('note', 250)->nullable();
            $table->timestampsTz();
            $table->unique(['domain_id', 'match_type', 'value', 'action']);
            $table->index(['domain_id', 'enabled', 'priority', 'id']);
        });
        Schema::create('security_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('edge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname', 253)->nullable();
            $table->string('state', 20)->nullable();
            $table->string('reason_code', 48);
            $table->jsonb('details')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['domain_id', 'occurred_at', 'id']);
        });
        Schema::create('emergency_modes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('target_type', 16);
            $table->string('target_id', 64);
            $table->jsonb('actions');
            $table->unsignedBigInteger('revision')->default(1);
            $table->boolean('active')->default(true);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['target_type', 'target_id', 'active']);
            $table->index(['active', 'expires_at']);
        });
        Schema::table('edge_pools', function (Blueprint $table): void {
            $table->boolean('withdrawn')->default(false);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_security_state_check CHECK (security_state IN ('normal','suspected','restricted','quarantined','recovering'))");
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_security_settings_object CHECK (security_settings IS NULL OR jsonb_typeof(security_settings) = 'object')");
            DB::statement("ALTER TABLE security_rules ADD CONSTRAINT security_rules_match_type_check CHECK (match_type IN ('ip','cidr','country','continent'))");
            DB::statement("ALTER TABLE security_rules ADD CONSTRAINT security_rules_action_check CHECK (action IN ('allow','block'))");
            DB::statement("ALTER TABLE emergency_modes ADD CONSTRAINT emergency_modes_target_type_check CHECK (target_type IN ('edge','cell','pool'))");
            DB::statement("ALTER TABLE emergency_modes ADD CONSTRAINT emergency_modes_actions_array CHECK (jsonb_typeof(actions) = 'array')");
        }
    }

    public function down(): void
    {
        Schema::table('edge_pools', fn (Blueprint $table) => $table->dropColumn('withdrawn'));
        Schema::dropIfExists('emergency_modes');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_rules');
        Schema::table('domains', fn (Blueprint $table) => $table->dropColumn(['security_settings', 'security_state', 'security_state_changed_at']));
    }
};
