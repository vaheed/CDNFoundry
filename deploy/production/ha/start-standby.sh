#!/usr/bin/env sh
# Starts only what a standby runs: the two database replicas, the Valkey replica, and monitoring.
# The application services stay stopped until promote.sh.
. "$(dirname "$0")/common.sh"

dc stop $APPLICATION_SERVICES >/dev/null 2>&1 || true
dc up -d --wait control-db pdns-db redis cache
for entry in $DATABASES; do
    service="${entry%%:*}"; rest="${entry#*:}"; user="${rest%%:*}"; rest="${rest#*:}"; database="${rest%%:*}"
    wait_for_recovery_state "$service" "$user" "$database" t 120 || { log "$service is not a replica"; exit 1; }
done
# Naming a service starts it whatever profile it belongs to; a bundle without monitoring has none of them.
dc up -d node-exporter prometheus alertmanager 2>/dev/null || true
log "standby running"
