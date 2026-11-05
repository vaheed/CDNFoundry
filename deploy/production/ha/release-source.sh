#!/usr/bin/env sh
# Run on the active node when the standby is removed: drops its replication slots so the primary stops
# retaining WAL for it.
. "$(dirname "$0")/common.sh"

for entry in $DATABASES; do
    service="${entry%%:*}"; rest="${entry#*:}"; user="${rest%%:*}"; rest="${rest#*:}"; database="${rest%%:*}"
    psql_in "$service" "$user" "$database" "select pg_drop_replication_slot(slot_name) from pg_replication_slots where slot_name like 'standby_%' and not active" >/dev/null
done
log "replication slots released"
