#!/bin/sh
set -eu

mkdir -p \
    /var/cache/nginx/content/small \
    /var/cache/nginx/content/standard \
    /var/cache/nginx/content/large \
    /var/cache/nginx/content/streaming
chown -R cdnf:cdnf /var/cache/nginx

exec "$@"
