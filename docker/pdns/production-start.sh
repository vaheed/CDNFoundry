#!/bin/sh
# Starts PowerDNS for production. The committed configurations carry development credentials, so
# this wrapper refuses to start with them: it takes the database password and API key from
# PDNS_DB_PASSWORD and PDNS_API_KEY, unless the mounted configuration was already rendered with
# real values (Fleet bundles). A node's SQLite store is created on first start.
set -eu
umask 077
source=/etc/powerdns/pdns.conf
runtime=/tmp/pdns
mkdir -p "$runtime"
cp "$source" "$runtime/pdns.conf"

render() {
    key=$1 variable=$2 development=$3
    # A configuration without the setting (a node has no database password) has nothing to render.
    grep -q "^${key}=" "$runtime/pdns.conf" || return 0
    current=$(sed -n "s/^${key}=//p" "$runtime/pdns.conf" | head -n 1)
    if [ -n "$current" ] && [ "$current" != "$development" ]; then
        return 0
    fi
    value=$(printenv "$variable" || true)
    if [ -z "$value" ] || [ "$value" = "$development" ]; then
        echo "Refusing to start PowerDNS: ${key} still has its development value. Set ${variable} to a unique secret." >&2
        exit 78
    fi
    RENDER_VALUE=$value awk -v key="$key" '
        index($0, key "=") == 1 { print key "=" ENVIRON["RENDER_VALUE"]; next }
        { print }
    ' "$runtime/pdns.conf" > "$runtime/pdns.conf.next"
    mv "$runtime/pdns.conf.next" "$runtime/pdns.conf"
}

render gpgsql-password PDNS_DB_PASSWORD pdns-dev-only
render api-key PDNS_API_KEY pdns-dev-api-key

database=$(sed -n 's/^gsqlite3-database=//p' "$runtime/pdns.conf" | head -n 1)
if [ -n "$database" ] && [ ! -e "$database" ]; then
    sqlite3 "$database" < /usr/local/share/doc/pdns/schema.sqlite3.sql
fi
# PowerDNS maps the GeoIP file into memory, and the updater replaces it by renaming a new file over it,
# so the running process would keep the old one. Reload when the file's identity changes.
mmdb=/mmdb/GeoLite2-City.mmdb
if [ -e "$mmdb" ]; then
    (
        last=$(ls -li "$mmdb" 2>/dev/null || true)
        while sleep 60; do
            current=$(ls -li "$mmdb" 2>/dev/null || true)
            if [ -n "$current" ] && [ "$current" != "$last" ]; then
                if pdns_control --config-dir="$runtime" reload >/dev/null 2>&1; then
                    echo "Reloaded GeoIP database"
                    last=$current
                fi
            fi
        done
    ) &
fi
exec /usr/local/sbin/pdns_server --config-dir="$runtime" "$@"
