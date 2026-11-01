#!/usr/bin/env sh
# Run on the standby. Optional argument: the positions printed by demote.sh ("control-db=0/1,pdns-db=0/2"),
# which promotion waits to have replayed. Promotes both databases, makes Valkey the primary, increments
# the control epoch, and prints it. It starts no application service: the fleet tool first replaces this
# node's standby bundle with its active bundle, then runs start.sh.
. "$(dirname "$0")/common.sh"

positions="${1:-}"
for entry in $DATABASES; do
    service="${entry%%:*}"; rest="${entry#*:}"; user="${rest%%:*}"; rest="${rest#*:}"; database="${rest%%:*}"
    target="$(printf '%s' "$positions" | tr ',' '\n' | sed -n "s/^${service}=//p")"
    if [ -n "$target" ]; then
        waited=0
        while [ "$(psql_in "$service" "$user" "$database" "select pg_last_wal_replay_lsn() >= '$target'::pg_lsn")" != t ]; do
            [ "$waited" -lt 300 ] || { log "$service has not replayed $target"; exit 1; }
            sleep 1; waited=$((waited + 1))
        done
    fi
    # Run again after an interrupted promotion, a database that already left recovery is left as it is.
    if [ "$(psql_in "$service" "$user" "$database" 'select pg_is_in_recovery()')" = t ]; then
        psql_in "$service" "$user" "$database" 'select pg_promote(true, 60)' >/dev/null
    fi
    wait_for_recovery_state "$service" "$user" "$database" f 60 || { log "$service did not leave recovery"; exit 1; }
    log "$service promoted"
done
password="$(sed -n 's/^requirepass //p' secrets/valkey.conf)"
dc exec -T -e "REDISCLI_AUTH=$password" redis valkey-cli replicaof no one >/dev/null
epoch="$(dc run --rm --no-deps -T core php artisan cdnf:control:manage promote --name "$HA_NODE")"
epoch="$(printf '%s\n' "$epoch" | tail -n 1)"
case "$epoch" in ''|*[!0-9]*) log "the epoch could not be incremented"; exit 1 ;; esac
# The fleet tool then renders and pushes this node's active bundle and runs its start.sh.
printf '%s\n' "$epoch"
