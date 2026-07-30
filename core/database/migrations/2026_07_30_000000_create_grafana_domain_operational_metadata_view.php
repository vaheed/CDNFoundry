<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE VIEW grafana_domain_operational_metadata AS
            SELECT
                d.id AS domain_id,
                d.lifecycle_state,
                d.revision AS desired_revision,
                d.active_edge_revision,
                d.revision_changed_at,
                d.nameservers_verified_at,
                COALESCE((d.proxy_settings ->> 'enabled')::boolean, true) AS proxy_enabled,
                p.state AS placement_state,
                p.desired_revision AS placement_revision,
                active_pool.name AS active_pool,
                target_pool.name AS target_pool,
                COALESCE(active_pool.cache_profile, target_pool.cache_profile) AS cache_profile,
                COALESCE(active_pool.compression_profile, target_pool.compression_profile) AS compression_profile,
                d.cache_settings ->> 'mode' AS cache_mode,
                d.cache_epoch,
                d.cache_development_mode_until,
                d.tls_mode,
                certificate.status AS certificate_status,
                certificate.expires_at AS certificate_expires_at,
                d.security_state,
                d.security_settings ->> 'profile' AS security_profile,
                d.waf_profile,
                COALESCE(assignments.assignment_count, 0) AS assignment_count,
                COALESCE(assignments.failed_assignments, 0) AS failed_assignments,
                COALESCE(assignments.assignments, '') AS cell_assignments,
                COALESCE(dns.deployment_count, 0) AS dns_deployment_count,
                COALESCE(dns.drifted_deployments, 0) AS dns_drifted_deployments
            FROM domains d
            LEFT JOIN domain_edge_placements p ON p.domain_id = d.id
            LEFT JOIN edge_pools active_pool ON active_pool.id = p.active_pool_id
            LEFT JOIN edge_pools target_pool ON target_pool.id = p.target_pool_id
            LEFT JOIN tls_certificates certificate ON certificate.id = d.active_tls_certificate_id
            LEFT JOIN LATERAL (
                SELECT
                    count(*) AS assignment_count,
                    count(*) FILTER (WHERE dec.state = 'failed') AS failed_assignments,
                    string_agg(
                        edge.name || '/' || COALESCE(active_cell.name, target_cell.name, 'unassigned') || ':' || dec.state,
                        ', ' ORDER BY edge.name, dec.replica
                    ) AS assignments
                FROM domain_edge_cells dec
                JOIN edges edge ON edge.id = dec.edge_id
                LEFT JOIN edge_cells active_cell ON active_cell.id = dec.active_cell_id
                LEFT JOIN edge_cells target_cell ON target_cell.id = dec.target_cell_id
                WHERE dec.domain_id = d.id
            ) assignments ON true
            LEFT JOIN LATERAL (
                SELECT
                    count(*) AS deployment_count,
                    count(*) FILTER (
                        WHERE dd.status IN ('pending', 'failed') OR dd.deployed_revision < dd.desired_revision
                    ) AS drifted_deployments
                FROM dns_deployments dd
                WHERE dd.domain_id = d.id
            ) dns ON true
            WHERE d.deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'cdnf_grafana') THEN
                    GRANT SELECT ON grafana_domain_operational_metadata TO cdnf_grafana;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP VIEW IF EXISTS grafana_domain_operational_metadata');
        }
    }
};
