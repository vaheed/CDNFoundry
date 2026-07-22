#!/bin/sh
set -eu

# Database migrations are deliberately not run here. Deployments invoke
# `php artisan migrate --force` through the explicit migrate service.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage

if [ "${1:-}" = "php-fpm" ]; then
    if [ -n "${EDGE_IDENTITY_CA_PRIVATE_KEY:-}" ] && ! su-exec www-data test -r "${EDGE_IDENTITY_CA_PRIVATE_KEY}"; then
        echo "The edge identity CA private key is not readable by the PHP-FPM worker; expected a restricted worker-readable secret." >&2
        exit 1
    fi
fi

if [ "$(id -u)" = "0" ] && [ "${1:-}" != "php-fpm" ]; then
    exec su-exec www-data "$@"
fi

exec "$@"
