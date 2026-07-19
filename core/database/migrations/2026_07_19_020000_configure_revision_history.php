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
            $table->timestampTz('revision_changed_at')->nullable();
        });
        DB::table('domains')->whereNull('revision_changed_at')->update(['revision_changed_at' => DB::raw('updated_at')]);
        Schema::table('edge_revisions', function (Blueprint $table): void {
            $table->index(['created_at', 'domain_id'], 'edge_revisions_retention_index');
        });
        Schema::table('edge_artifacts', function (Blueprint $table): void {
            $table->index(['domain_id', 'revision'], 'edge_artifacts_domain_revision_index');
        });
        DB::table('system_settings')->insertOrIgnore([
            'group' => 'revision_history',
            'values' => json_encode([
                'retention_days' => 1,
                'minimum_revisions_per_domain' => 10,
            ], JSON_THROW_ON_ERROR),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'revision_history')->delete();
        Schema::table('edge_artifacts', function (Blueprint $table): void {
            $table->dropIndex('edge_artifacts_domain_revision_index');
        });
        Schema::table('edge_revisions', function (Blueprint $table): void {
            $table->dropIndex('edge_revisions_retention_index');
        });
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn('revision_changed_at');
        });
    }
};
