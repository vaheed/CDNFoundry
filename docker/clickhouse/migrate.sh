#!/bin/sh
# Apply docker/clickhouse/migrations/*.sql in name order, once each, and record them in
# cdnf.schema_migrations with the file's checksum. A recorded migration whose file has changed is an
# error: write a new migration instead. Every migration is idempotent, so a fresh database (whose
# init.sql already holds the current schema) and an older one both converge.
set -eu

host="${CLICKHOUSE_HOST:-clickhouse}"
directory="${MIGRATIONS_DIRECTORY:-/migrations}"
client() {
    clickhouse-client --host "$host" --user "${CLICKHOUSE_USER:-cdnf}" --password "$CLICKHOUSE_PASSWORD" --database cdnf "$@"
}

for _ in $(seq 1 60); do
    client --query 'SELECT 1' >/dev/null 2>&1 && break
    sleep 2
done
client --query 'SELECT 1' >/dev/null

client --query "CREATE TABLE IF NOT EXISTS cdnf.schema_migrations (name String, checksum FixedString(64), applied_at DateTime DEFAULT now()) ENGINE = MergeTree ORDER BY name"

applied=0
for file in $(ls "$directory"/*.sql | sort); do
    name="$(basename "$file")"
    checksum="$(sha256sum "$file" | cut -d' ' -f1)"
    recorded="$(client --query "SELECT checksum FROM cdnf.schema_migrations WHERE name = '$name' LIMIT 1")"
    if [ "$recorded" = "$checksum" ]; then
        echo "skip $name"
        continue
    fi
    if [ -n "$recorded" ]; then
        echo "$name changed after it was applied; add a new migration instead of editing it." >&2
        exit 1
    fi
    client --multiquery < "$file"
    client --query "INSERT INTO cdnf.schema_migrations (name, checksum) VALUES ('$name', '$checksum')"
    echo "applied $name"
    applied=$((applied + 1))
done
echo "clickhouse_migrations_applied=$applied"
