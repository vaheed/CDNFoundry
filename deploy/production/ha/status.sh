#!/usr/bin/env sh
# Prints this node's high availability state as one JSON object. Secrets appear only as fingerprints.
. "$(dirname "$0")/common.sh"

fingerprint() { # file [sed-expression]
    if [ -r "$1" ]; then
        if [ $# -gt 1 ]; then sed -n "$2" "$1"; else cat "$1"; fi | sha256sum | cut -c1-16
    else
        echo missing
    fi
}

database_json() { # service user database
    service="$1"; user="$2"; database="$3"
    recovery="$(psql_in "$service" "$user" "$database" 'select pg_is_in_recovery()' 2>/dev/null || echo unreachable)"
    if [ "$recovery" = "t" ]; then
        lag="$(psql_in "$service" "$user" "$database" "select case when pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() then 0 else coalesce(extract(epoch from now() - pg_last_xact_replay_timestamp())::int, 0) end" 2>/dev/null || echo null)"
        lsn="$(psql_in "$service" "$user" "$database" 'select pg_last_wal_replay_lsn()' 2>/dev/null || echo)"
        receiving="$(psql_in "$service" "$user" "$database" "select count(*) from pg_stat_wal_receiver where status = 'streaming'" 2>/dev/null || echo 0)"
        printf '{"in_recovery":true,"lag_seconds":%s,"lsn":"%s","streaming":%s}' "${lag:-null}" "$lsn" "$([ "$receiving" = 1 ] && echo true || echo false)"
    elif [ "$recovery" = "f" ]; then
        lsn="$(psql_in "$service" "$user" "$database" 'select pg_current_wal_lsn()' 2>/dev/null || echo)"
        readonly="$(psql_in "$service" "$user" "$database" 'show default_transaction_read_only' 2>/dev/null || echo off)"
        replicas="$(psql_in "$service" "$user" "$database" "select count(*) from pg_stat_replication where state = 'streaming'" 2>/dev/null || echo 0)"
        printf '{"in_recovery":false,"read_only":%s,"lsn":"%s","streaming_replicas":%s}' "$([ "$readonly" = on ] && echo true || echo false)" "$lsn" "${replicas:-0}"
    else
        printf '{"in_recovery":null}'
    fi
}

valkey_json() {
    password="$(sed -n 's/^requirepass //p' secrets/valkey.conf)"
    info="$(dc exec -T -e "REDISCLI_AUTH=$password" redis valkey-cli info replication 2>/dev/null | tr -d '\r' || true)"
    role="$(printf '%s\n' "$info" | sed -n 's/^role://p')"
    link="$(printf '%s\n' "$info" | sed -n 's/^master_link_status://p')"
    printf '{"role":"%s","link":"%s"}' "${role:-unreachable}" "${link:-none}"
}

app_fingerprints="$(dc run --rm --no-deps -T core php artisan cdnf:control:fingerprints 2>/dev/null | tail -n 1 || echo '{}')"
[ -n "$app_fingerprints" ] || app_fingerprints='{}'
printf '{"node":"%s","role":"%s","control_db":%s,"pdns_db":%s,"valkey":%s,"files":{' "$HA_NODE" "$HA_ROLE" \
    "$(database_json control-db cdnf cdnf)" "$(database_json pdns-db pdns pdns)" "$(valkey_json)"
printf '"edge_identity_ca_certificate":"%s",' "$(fingerprint pki/edge-identity-ca.crt)"
printf '"edge_identity_ca_private_key":"%s",' "$(fingerprint pki/edge-identity-ca.key)"
printf '"edge_server_ca_certificate":"%s",' "$(fingerprint pki/edge-server-ca.crt)"
printf '"edge_proxy_secret":"%s",' "$(fingerprint secrets/edge-proxy-secret.conf)"
printf '"valkey_password":"%s",' "$(fingerprint secrets/valkey.conf 's/^requirepass //p')"
printf '"replication_password":"%s",' "$(fingerprint secrets/replication-password)"
printf '"ingress_certificate":"%s"' "$(fingerprint ha/ingress.crt)"
printf '},"application":%s}\n' "$app_fingerprints"
