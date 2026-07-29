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
            $table->string('waf_profile', 16)->default('off')->after('security_state_changed_at');
        });
        Schema::table('edge_pools', function (Blueprint $table): void {
            $table->boolean('waf_capable')->default(false)->after('compression_profile');
            $table->string('waf_runtime_version', 80)->nullable()->after('waf_capable');
            $table->string('waf_canary_state', 20)->default('not_required')->after('waf_runtime_version');
        });
        Schema::create('waf_exclusions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 20);
            $table->string('value', 255);
            $table->unsignedInteger('rule_id')->nullable();
            $table->string('reason', 255);
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['domain_id', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_waf_profile_check CHECK (waf_profile IN ('off', 'monitor', 'balanced', 'strict'))");
            DB::statement("ALTER TABLE edge_pools ADD CONSTRAINT edge_pools_waf_canary_state_check CHECK (waf_canary_state IN ('not_required', 'monitoring', 'passed', 'failed'))");
            DB::statement("ALTER TABLE waf_exclusions ADD CONSTRAINT waf_exclusions_dimension_check CHECK (dimension IN ('path', 'rule', 'parameter', 'cookie'))");
            DB::statement('ALTER TABLE waf_exclusions ADD CONSTRAINT waf_exclusions_expiry_check CHECK (expires_at > created_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('waf_exclusions');
        Schema::table('edge_pools', fn (Blueprint $table) => $table->dropColumn(['waf_capable', 'waf_runtime_version', 'waf_canary_state']));
        Schema::table('domains', fn (Blueprint $table) => $table->dropColumn('waf_profile'));
    }
};
