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
GRANT SELECT (id, name, display_name, deleted_at) ON TABLE domains TO cdnf_grafana;
SELECT 'GRANT SELECT ON TABLE grafana_domain_operational_metadata TO cdnf_grafana'
WHERE to_regclass('public.grafana_domain_operational_metadata') IS NOT NULL \gexec
