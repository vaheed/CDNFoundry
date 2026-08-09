#!/bin/sh
set -eu

# The entrypoint only initializes an empty volume. Reapply the idempotent base
# schema so a previously interrupted initialization is repaired without
# deleting persistent data.
psql -v ON_ERROR_STOP=1 \
    -U "${POSTGRES_USER:-pdns}" \
    -d "${POSTGRES_DB:-pdns}" \
    -f /docker-entrypoint-initdb.d/10-schema.sql

# POSTGRES_PASSWORD is desired state from the active node bundle. Feed the SQL
# through stdin so psql performs literal-safe variable quoting and the secret is
# not included in argv or output.
psql -v ON_ERROR_STOP=1 \
    -U "${POSTGRES_USER:-pdns}" \
    -d "${POSTGRES_DB:-pdns}" \
    -v new_password="$POSTGRES_PASSWORD" <<'SQL'
ALTER ROLE pdns PASSWORD :'new_password';
SQL
