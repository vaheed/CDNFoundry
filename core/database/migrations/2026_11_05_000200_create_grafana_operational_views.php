<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Read-only views for Grafana. Each exposes only the columns a dashboard needs, so the Grafana role never
 * reads certificate material, artifact payloads, audit metadata, or full client addresses.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const VIEWS = [
        'grafana_edges' => 'SELECT id, name, country_code, continent_code, enabled, drained, last_heartbeat_at, registered_at, agent_version, active_sequence FROM edges',
        'grafana_edge_cells' => 'SELECT edge_id, edge_pool_id, name, status, drained FROM edge_cells',
        'grafana_edge_artifacts' => 'SELECT sequence, edge_id, domain_id, kind, revision, created_at FROM edge_artifacts',
        'grafana_cache_purges' => 'SELECT id, domain_id, type, status, created_at, updated_at FROM cache_purges',
        'grafana_fleet_rollouts' => 'SELECT id, status, current_wave, maximum_parallel, pause_reason, started_at, finished_at, created_at FROM fleet_rollouts',
        'grafana_edge_tasks' => 'SELECT id, edge_id, type, status, created_at, finished_at FROM edge_tasks',
        'grafana_placements' => 'SELECT domain_id, state, active_pool_id, target_pool_id, updated_at FROM domain_edge_placements',
        'grafana_audit_events' => "SELECT id, actor_id, action, created_at, CASE WHEN ip_address IS NULL OR ip_address = '' THEN NULL WHEN position(':' in ip_address) > 0 THEN host(set_masklen(ip_address::inet, 48)) || '/48' ELSE host(set_masklen(ip_address::inet, 24)) || '/24' END AS network FROM audit_logs",
        'grafana_tls_certificates' => 'SELECT id, domain_id, kind, status, names, expires_at, activated_at, last_failure_at FROM tls_certificates',
        'grafana_tls_orders' => 'SELECT id, domain_id, status, started_at, finished_at FROM tls_orders',
        'grafana_backups' => 'SELECT id, status, size_bytes, verified_at, created_at FROM backups',
        'grafana_emergency_modes' => 'SELECT id, target_type, active, expires_at, created_at FROM emergency_modes',
        'grafana_usage_rollups' => 'SELECT domain_id, interval_start, interval_end, granularity, status, requests, bytes_in, bytes_out, cache_hits, dns_queries FROM usage_rollups',
        'grafana_waf_exclusions' => 'SELECT id, domain_id, dimension, rule_id, expires_at, created_at FROM waf_exclusions',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::VIEWS as $name => $select) {
            DB::statement("CREATE OR REPLACE VIEW {$name} AS {$select}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys(self::VIEWS) as $name) {
            DB::statement("DROP VIEW IF EXISTS {$name}");
        }
    }
};
