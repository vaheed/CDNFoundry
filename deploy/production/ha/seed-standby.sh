#!/usr/bin/env sh
# Run on a new standby. Copies both databases from the active node and starts the replicas. It refuses
# to touch a data directory that already holds data: use rejoin.sh for a node that was active before.
. "$(dirname "$0")/common.sh"
prepare_permissions

for entry in $DATABASES; do
    service="${entry%%:*}"; port="${entry##*:}"
    if dc run --rm --no-deps -T --entrypoint sh "$service" -c 'test -f "$PGDATA/PG_VERSION"'; then
        if dc run --rm --no-deps -T --entrypoint sh "$service" -c 'test -f "$PGDATA/standby.signal"'; then
            log "$service is already a copy of the active node; keeping it"
            continue
        fi
        log "$service already holds data that is not a replica; refusing to overwrite it. Use rejoin.sh for a former active node."
        exit 1
    fi
    log "copying $service from $HA_PEER_ADDRESS:$port"
    basebackup "$service" "$port" "standby_$(printf '%s' "$HA_NODE" | tr -c 'a-z0-9\n' '_')_${service%%-*}"
done
exec ./ha/start-standby.sh
