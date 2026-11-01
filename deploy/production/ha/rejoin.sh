#!/usr/bin/env sh
# Run on a former active node after the other node was promoted. Rebuilds both databases as replicas of
# the new active node: pg_rewind when possible, otherwise a new base backup. The old data directory is
# renamed, never deleted.
. "$(dirname "$0")/common.sh"
prepare_permissions

dc stop $APPLICATION_SERVICES >/dev/null 2>&1 || true
export HA_PEER_ADDRESS HA_NODE PGPASSFILE=/run/secrets/replication.pgpass
for entry in $DATABASES; do
    service="${entry%%:*}"; port="${entry##*:}"
    slot="standby_$(printf '%s' "$HA_NODE" | tr -c 'a-z0-9\n' '_')_${service%%-*}"
    dc stop "$service" >/dev/null 2>&1 || true
    if in_database_container "$service" "$port" "$slot" '
        set -eu
        pg_rewind --target-pgdata="$PGDATA" --source-server="host=$HA_PEER_ADDRESS port=$HA_TARGET_PORT user=replicator dbname=postgres passfile=/run/secrets/replication.pgpass"
    ' >&2; then
        log "$service rewound"
        in_database_container "$service" "$port" "$slot" "set -eu; $SCRIPT_CONFIGURE_STANDBY"
    else
        log "$service could not be rewound; taking a new base backup and keeping the old directory"
        # The parent directory belongs to root, so the old data directory is renamed, and an empty one created, as root.
        HA_DB_USER=root in_database_container "$service" "$port" "$slot" 'set -eu; mv "$PGDATA" "$PGDATA.old-$(date +%Y%m%d%H%M%S)"; mkdir "$PGDATA"; chown postgres:postgres "$PGDATA"; chmod 0700 "$PGDATA"'
        basebackup "$service" "$port" "$slot"
    fi
done
password="$(sed -n 's/^requirepass //p' secrets/valkey.conf)"
./ha/start-standby.sh
dc exec -T -e "REDISCLI_AUTH=$password" redis valkey-cli replicaof "$HA_PEER_ADDRESS" 6379 >/dev/null
log "rejoined as standby"
