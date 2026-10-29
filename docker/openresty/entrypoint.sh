#!/bin/sh
set -eu

if [ "${EDGE_REQUIRE_MEMORY_TLS:-false}" = true ]; then
    [ "$(wc -l < /proc/swaps)" -le 1 ] || {
        echo 'edge hosts must disable swap for memory-backed TLS keys' >&2
        exit 1
    }
    [ "$(stat -f -c %T /run/edge/tls.key)" = tmpfs ] || {
        echo 'edge bootstrap private key must reside on tmpfs' >&2
        exit 1
    }
    [ "$(stat -f -c %T /var/lib/cdnfoundry/runtime)" = tmpfs ] || {
        echo 'edge runtime must reside on tmpfs' >&2
        exit 1
    }
fi

mkdir -p \
    /var/cache/nginx/content/small \
    /var/cache/nginx/content/standard \
    /var/cache/nginx/content/large \
    /var/cache/nginx/content/streaming
chown -R cdnf:cdnf /var/cache/nginx

if [ "${1:-}" = /usr/local/openresty/bin/openresty ] && ! getent hosts vector >/dev/null 2>&1; then
    # OpenResty resolves syslog destinations while loading its configuration.
    # Keep serving from the bounded Docker stdout log if Vector is unavailable
    # during startup; a telemetry outage must not make every cell unavailable.
    fallback_config=/var/lib/nginx/tmp/nginx-without-syslog.conf
    sed '/^[[:space:]]*access_log syslog:server=vector:9000,/d' \
        /usr/local/openresty/nginx/conf/nginx.conf > "$fallback_config"
    echo 'Vector DNS unavailable; serving with bounded stdout telemetry only' >&2
    exec "$@" -c "$fallback_config"
fi

exec "$@"
