#!/usr/bin/env sh
# Run on the active node. Creates the replication role in each database and restarts the databases
# with the replication listener and access rules the fleet generator rendered. Idempotent.
. "$(dirname "$0")/common.sh"

dc up -d --wait --no-deps control-db pdns-db
password="$(replication_password)"
for entry in $DATABASES; do
    service="${entry%%:*}"; rest="${entry#*:}"; user="${rest%%:*}"; rest="${rest#*:}"; database="${rest%%:*}"
    dc exec -T "$service" psql -U "$user" -d "$database" -v ON_ERROR_STOP=1 -v "replication_password=$password" <<'SQL' >/dev/null
DO $$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'replicator') THEN
        CREATE ROLE replicator REPLICATION LOGIN;
    END IF;
END $$;
ALTER ROLE replicator PASSWORD :'replication_password';
SQL
    # pg_rewind reads the primary's files through these functions, in the postgres database it connects to.
    dc exec -T "$service" psql -U "$user" -d postgres -v ON_ERROR_STOP=1 >/dev/null <<'SQL'
GRANT EXECUTE ON FUNCTION pg_catalog.pg_ls_dir(text, boolean, boolean) TO replicator;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_stat_file(text, boolean) TO replicator;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text) TO replicator;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_read_binary_file(text, bigint, bigint, boolean) TO replicator;
SQL
    log "replication role ready in $service"
done
# Valkey: the password is the queue password; the replica authenticates with the same value.
log "source prepared"
