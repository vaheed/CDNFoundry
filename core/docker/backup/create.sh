#!/bin/sh
set -eu
# Hourly and daily usage rollups survive the loss of the telemetry host. Raw events are not backed up:
# they expire after seven days and the platform serves without them. A rollup failure never stops the
# control backup, which is the one that matters for recovery. The export ends with a completion line, so a
# stream that was cut short is never mistaken for a backup: the restore uses only complete snapshots.
rollups="${CLICKHOUSE_URL:-}"
if [ -n "$rollups" ] && [ -n "${CLICKHOUSE_PASSWORD:-}" ]; then
    export_rollups() {
        for table in edge_hourly dns_hourly edge_daily dns_daily; do
            printf -- '-- table: %s\n' "$table"
            curl --silent --show-error --fail --max-time 3000 --user "${CLICKHOUSE_USER:-cdnf_reader}:${CLICKHOUSE_PASSWORD}" --get \
                --data-urlencode "query=SELECT * FROM cdnf.${table} FORMAT JSONEachRow" "$rollups/" || return 1
        done
        printf -- '-- complete\n'
    }
    # Telemetry being down is not worth a snapshot of nothing.
    if curl --silent --fail --max-time 5 "$rollups/ping" >/dev/null 2>&1; then
        if ! export_rollups | restic backup --stdin --stdin-filename rollups.jsonl --tag cdnfoundry-rollups --json; then
            echo '{"message_type":"warning","message":"usage rollups were not backed up"}' >&2
        fi
    else
        echo '{"message_type":"warning","message":"ClickHouse did not answer; usage rollups were not backed up"}' >&2
    fi
fi
pg_dump --format=custom --no-owner --no-privileges | restic backup --stdin --stdin-filename control.pgdump --tag cdnfoundry-control --json
