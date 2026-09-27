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

exec "$@"
