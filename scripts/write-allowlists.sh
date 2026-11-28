#!/bin/sh
# Writes the address lists that the gateways and the control plane read, from the values in an environment file.
#
# Usage: scripts/write-allowlists.sh [ENV_FILE] [DIRECTORY]
#   ENV_FILE   defaults to .env.prod
#   DIRECTORY  defaults to generated/allowlists (next to the Compose file, mounted by the gateways and the control plane)
#
# A list lives in a file and not in a container environment value because an environment value is fixed when the
# container is created. After editing a list, run this script again and reload the gateway without recreating it:
#   docker compose --env-file .env.prod kill -s USR1 caddy telemetry-gateway
# Fleet writes the same files itself; this script is for installs that edit the environment file by hand.
set -eu

env_file=${1:-.env.prod}
directory=${2:-generated/allowlists}
[ -f "$env_file" ] || { printf 'Environment file not found: %s\n' "$env_file" >&2; exit 1; }

value() {
    # The last assignment wins; surrounding quotes are removed. Nothing in the file is executed.
    sed -n "s/^$1=//p" "$env_file" | tail -n 1 | sed "s/^['\"]//; s/['\"]\$//"
}
join() {
    printf '%s' "$*" | tr -s ' ' | sed 's/^ //; s/ $//'
}
cidrs() {
    for item in $1; do
        case "$item" in
            *[!0-9a-fA-F:./]*) printf 'Unsupported character in an address list: %s\n' "$item" >&2; exit 2 ;;
        esac
    done
}

management=$(value MANAGEMENT_NETWORKS)
edge=$(join "$(value EDGE_PUBLIC_IPV4_ALLOWLIST) $(value EDGE_PUBLIC_IPV6_ALLOWLIST)")
control=$(join "$(value CONTROL_PUBLIC_IPV4_ALLOWLIST) $(value CONTROL_PUBLIC_IPV6_ALLOWLIST)")
logs=$(join "$(value LOG_SOURCE_IPV4_ALLOWLIST) $(value LOG_SOURCE_IPV6_ALLOWLIST)")
for list in "$management" "$edge" "$control" "$logs"; do cidrs "$list"; done

umask 022
mkdir -p "$directory"
chmod 755 "$directory"
write() {
    # Write beside the target, then rename: the gateway mounts the directory, so it never reads a half-written file.
    tmp=$(mktemp "$directory/.write.XXXXXX")
    printf '%s\n' "$2" > "$tmp"
    chmod 644 "$tmp"
    mv "$tmp" "$directory/$1"
}
write management-networks.caddy "remote_ip ${management:-0.0.0.0/0 ::/0}"
write management-networks.txt "$management"
write log-sources.caddy "remote_ip $logs"
write telemetry-log-sources.caddy "remote_ip $(join "$edge $logs")"
write clickhouse-sources.caddy "remote_ip $(join "$edge $control")"
