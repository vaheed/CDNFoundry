\set ON_ERROR_STOP on

SELECT format('CREATE ROLE cdnf_grafana LOGIN PASSWORD %L', :'grafana_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'cdnf_grafana') \gexec

SELECT format('ALTER ROLE cdnf_grafana PASSWORD %L', :'grafana_password') \gexec
ALTER ROLE cdnf_grafana SET default_transaction_read_only = on;
ALTER ROLE cdnf_grafana SET statement_timeout = '30s';
ALTER ROLE cdnf_grafana SET lock_timeout = '2s';
ALTER ROLE cdnf_grafana SET idle_in_transaction_session_timeout = '30s';

REVOKE ALL ON DATABASE cdnf FROM cdnf_grafana;
GRANT CONNECT ON DATABASE cdnf TO cdnf_grafana;
GRANT USAGE ON SCHEMA public TO cdnf_grafana;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM cdnf_grafana;
GRANT SELECT (id, name, display_name, deleted_at, created_at) ON TABLE domains TO cdnf_grafana;
SELECT 'GRANT SELECT ON TABLE grafana_domain_operational_metadata TO cdnf_grafana'
WHERE to_regclass('public.grafana_domain_operational_metadata') IS NOT NULL \gexec

-- Operational views created by the application's migrations. They hide certificate material, artifact
-- payloads, audit metadata, and full client addresses. A view the running version does not have yet is skipped.
SELECT format('GRANT SELECT ON TABLE %I TO cdnf_grafana', view_name)
FROM unnest(ARRAY[
    'grafana_edges', 'grafana_edge_cells', 'grafana_edge_artifacts', 'grafana_cache_purges', 'grafana_fleet_rollouts',
    'grafana_edge_tasks', 'grafana_placements', 'grafana_audit_events', 'grafana_tls_certificates', 'grafana_tls_orders',
    'grafana_backups', 'grafana_emergency_modes', 'grafana_waf_exclusions', 'grafana_usage_rollups'
]) AS view_name
WHERE to_regclass(format('public.%I', view_name)) IS NOT NULL \gexec
