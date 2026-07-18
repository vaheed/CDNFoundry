<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_cells', function (Blueprint $table): void {
            $table->ipAddress('service_ipv4')->nullable()->after('status')->unique();
            $table->ipAddress('service_ipv6')->nullable()->after('service_ipv4')->unique();
        });
        Schema::table('edges', function (Blueprint $table): void {
            $table->char('identity_csr_hash', 64)->nullable()->after('identity_hash');
            $table->text('identity_certificate')->nullable()->after('identity_csr_hash');
            $table->timestampTz('bootstrap_consumed_at')->nullable()->after('identity_certificate');
        });

        $sharedPoolId = DB::table('edge_pools')->where('kind', 'shared')->orderBy('id')->value('id');
        if ($sharedPoolId !== null) {
            DB::table('edge_cells')
                ->where('edge_pool_id', $sharedPoolId)
                ->whereNull('service_ipv4')
                ->update([
                    'service_ipv4' => DB::raw('(SELECT edges.ipv4 FROM edges WHERE edges.id = edge_cells.edge_id)'),
                    'service_ipv6' => DB::raw('(SELECT edges.ipv6 FROM edges WHERE edges.id = edge_cells.edge_id)'),
                ]);
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            ['domains', 'proxy_settings'],
            ['dns_records', 'origin'],
            ['dns_records', 'origin_health'],
            ['edges', 'capacity'],
            ['edge_cells', 'capacity'],
            ['edge_revisions', 'snapshot'],
            ['edge_artifacts', 'payload'],
            ['edge_tasks', 'payload'],
            ['edge_tasks', 'result'],
        ] as [$table, $column]) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
        }

        DB::statement("ALTER TABLE edge_cells ADD CONSTRAINT edge_cells_status_check CHECK (status IN ('pending', 'ready', 'degraded', 'failed', 'drained'))");
        DB::statement("ALTER TABLE edge_artifacts ADD CONSTRAINT edge_artifacts_kind_check CHECK (kind IN ('domain', 'tombstone'))");
        DB::statement('ALTER TABLE edge_artifacts ADD CONSTRAINT edge_artifacts_revision_positive CHECK (revision > 0)');
        DB::statement("ALTER TABLE edge_revisions ADD CONSTRAINT edge_revisions_status_check CHECK (status IN ('validated', 'rejected'))");
        DB::statement("ALTER TABLE edge_tasks ADD CONSTRAINT edge_tasks_status_check CHECK (status IN ('pending', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE operations ADD CONSTRAINT operations_status_check CHECK (status IN ('pending', 'running', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE platform_dns_deployments ADD CONSTRAINT platform_dns_deployments_status_check CHECK (status IN ('pending', 'deploying', 'succeeded', 'failed', 'obsolete'))");
        DB::statement('ALTER TABLE edges ADD CONSTRAINT edges_geo_codes_check CHECK (country_code = upper(country_code) AND continent_code = upper(continent_code))');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE edges DROP CONSTRAINT IF EXISTS edges_geo_codes_check');
            DB::statement('ALTER TABLE platform_dns_deployments DROP CONSTRAINT IF EXISTS platform_dns_deployments_status_check');
            DB::statement('ALTER TABLE operations DROP CONSTRAINT IF EXISTS operations_status_check');
            DB::statement('ALTER TABLE edge_tasks DROP CONSTRAINT IF EXISTS edge_tasks_status_check');
            DB::statement('ALTER TABLE edge_revisions DROP CONSTRAINT IF EXISTS edge_revisions_status_check');
            DB::statement('ALTER TABLE edge_artifacts DROP CONSTRAINT IF EXISTS edge_artifacts_revision_positive');
            DB::statement('ALTER TABLE edge_artifacts DROP CONSTRAINT IF EXISTS edge_artifacts_kind_check');
            DB::statement('ALTER TABLE edge_cells DROP CONSTRAINT IF EXISTS edge_cells_status_check');
        }

        Schema::table('edge_cells', function (Blueprint $table): void {
            $table->dropUnique(['service_ipv4']);
            $table->dropUnique(['service_ipv6']);
            $table->dropColumn(['service_ipv4', 'service_ipv6']);
        });
        Schema::table('edges', function (Blueprint $table): void {
            $table->dropColumn(['identity_csr_hash', 'identity_certificate', 'bootstrap_consumed_at']);
        });
    }
};
