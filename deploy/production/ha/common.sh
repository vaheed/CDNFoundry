# Sourced by the other HA scripts. Runs from the node's bundle directory.
# Everything node specific (names, addresses, ports) comes from ha/ha.env, which the fleet generator
# renders. No secret is read from an argument or printed: the replication password lives in a mode 0600
# file and reaches libpq through a passfile or the environment.
set -eu
cd "$(dirname "$0")/.."
# shellcheck disable=SC1091
. ./ha/ha.env

dc() { docker compose --env-file .env.prod "$@"; }

# name:user:database:port for each PostgreSQL instance that holds replicated state.
DATABASES="control-db:cdnf:cdnf:${HA_CONTROL_DB_PORT} pdns-db:pdns:pdns:${HA_PDNS_DB_PORT}"

# The application services that must not run on a standby, a demoted node, or a replaced node.
APPLICATION_SERVICES="caddy web edge-control horizon scheduler core pdns-primary"

log() { printf '%s\n' "ha: $*" >&2; }

psql_in() { # service user database sql
    dc exec -T "$1" psql -U "$2" -d "$3" -v ON_ERROR_STOP=1 -At -c "$4"
}

# File owners the containers need. libpq reads the passfile as the postgres user (uid 70 in the image) and
# ignores a file that is not mode 0600; Valkey (uid 1000) reads its configuration; PHP (gid 82) reads the CA
# key and the shared secrets. A standby that was never started has none of this set yet.
prepare_permissions() {
    chown 70:70 ha/replication.pgpass
    chmod 0600 ha/replication.pgpass
    chown 0:1000 secrets/valkey.conf secrets/valkey-cache.conf
    chmod 0640 secrets/valkey.conf secrets/valkey-cache.conf
    chown 0:82 pki/edge-identity-ca.key docker/pdns/primary.conf secrets/metrics-token secrets/edge-proxy-secret.conf
    chmod 0640 pki/edge-identity-ca.key docker/pdns/primary.conf secrets/metrics-token secrets/edge-proxy-secret.conf
}

replication_password() { cat secrets/replication-password; }

# Runs a script inside a one-shot container of one database service, as the postgres user, with the
# node's replication settings in its environment. Values reach the script as variables, never spliced
# into its text.
in_database_container() { # service port slot script
    HA_TARGET_PORT="$2" HA_SLOT="$3" dc run --rm --no-deps -T --user "${HA_DB_USER:-postgres}" \
        -e HA_PEER_ADDRESS -e HA_NODE -e HA_TARGET_PORT -e HA_SLOT -e PGPASSFILE --entrypoint sh "$1" -c "$4"
}

SCRIPT_CONFIGURE_STANDBY='
    touch "$PGDATA/standby.signal"
    {
        echo "primary_conninfo = '"'"'host=$HA_PEER_ADDRESS port=$HA_TARGET_PORT user=replicator passfile=/run/secrets/replication.pgpass application_name=$HA_NODE'"'"'"
        echo "hot_standby = on"
        echo "default_transaction_read_only = off"
    } >> "$PGDATA/postgresql.auto.conf"
'

# Streams a base backup of the peer into the (empty) data directory of one service and points it at the
# peer through a passfile, so the password is never written to postgresql.auto.conf.
basebackup() { # service port slot
    export HA_PEER_ADDRESS HA_NODE PGPASSFILE=/run/secrets/replication.pgpass
    HA_TARGET_PORT="$2" HA_SLOT="$3" dc run --rm --no-deps -T --user postgres \
        -e HA_PEER_ADDRESS -e HA_NODE -e HA_TARGET_PORT -e HA_SLOT -e PGPASSFILE --entrypoint sh "$1" -c '
        set -eu
        mkdir -p "$PGDATA" && chmod 0700 "$PGDATA"
        pg_basebackup -h "$HA_PEER_ADDRESS" -p "$HA_TARGET_PORT" -U replicator -D "$PGDATA" -X stream -C -S "$HA_SLOT" --checkpoint=fast
        '"$SCRIPT_CONFIGURE_STANDBY"
}

wait_for_recovery_state() { # service user database expected(t|f) seconds
    waited=0
    while [ "$waited" -lt "$5" ]; do
        state="$(psql_in "$1" "$2" "$3" 'select pg_is_in_recovery()' 2>/dev/null || true)"
        [ "$state" = "$4" ] && return 0
        sleep 2; waited=$((waited + 2))
    done
    return 1
}
