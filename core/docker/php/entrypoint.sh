#!/bin/sh
set -eu

# Database migrations are deliberately not run here. Deployments invoke
# `php artisan migrate --force` through the explicit migrate service.
exec "$@"

