#!/bin/sh
set -eu

# Database migrations are deliberately not run here. Deployments invoke
# `php artisan migrate --force` through the explicit migrate service.
if [ "${1:-}" = "php-fpm" ]; then
    php artisan filament:assets
fi

exec "$@"
