#!/usr/bin/env sh
# Run on the active node for planned maintenance or a switchover. Stops every application service and
# makes both databases read-only, then prints the final write positions. promote.sh on the other node
# waits for exactly those positions, so no committed write is lost.
. "$(dirname "$0")/common.sh"

dc stop $APPLICATION_SERVICES >/dev/null 2>&1 || true
positions=""
for entry in $DATABASES; do
    service="${entry%%:*}"; rest="${entry#*:}"; user="${rest%%:*}"; rest="${rest#*:}"; database="${rest%%:*}"
    psql_in "$service" "$user" "$database" 'alter system set default_transaction_read_only = on' >/dev/null
    psql_in "$service" "$user" "$database" 'select pg_reload_conf()' >/dev/null
    # End any session that was already open, so none can keep writing.
    psql_in "$service" "$user" "$database" "select pg_terminate_backend(pid) from pg_stat_activity where pid <> pg_backend_pid() and backend_type = 'client backend'" >/dev/null
    psql_in "$service" "$user" "$database" 'select pg_switch_wal()' >/dev/null
    lsn="$(psql_in "$service" "$user" "$database" 'select pg_current_wal_lsn()')"
    positions="${positions}${positions:+,}${service}=${lsn}"
done
log "demoted; final positions $positions"
printf '%s\n' "$positions"
