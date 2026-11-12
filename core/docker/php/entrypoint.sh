#!/bin/sh
set -eu

# Database migrations are deliberately not run here. Deployments invoke
# `php artisan migrate --force` through the explicit migrate service.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage

if [ "${1:-}" = "php-fpm" ]; then
    # The master runs as www-data, which cannot reopen the root-owned log pipe through /proc/self/fd/2, so the
    # stock Docker overrides are not used. FPM logs into a named pipe that a www-data process copies to the
    # container's stderr. The stock pool file keeps the process limits. By default FPM listens on every address, for the development stack. When FPM_LISTEN_ALIAS
    # names an alias of the private fpm network, it listens only on that network's address, so no other
    # container on the control network can reach it.
    FPM_LISTEN="9000"
    if [ -n "${FPM_LISTEN_ALIAS:-}" ]; then
        FPM_LISTEN_ADDRESS="$(getent hosts "${FPM_LISTEN_ALIAS}" | awk 'NR == 1 { print $1 }')"
        if [ -z "${FPM_LISTEN_ADDRESS}" ]; then
            echo "The ${FPM_LISTEN_ALIAS} alias does not resolve; attach the service to the fpm network with that alias." >&2
            exit 1
        fi
        FPM_LISTEN="${FPM_LISTEN_ADDRESS}:9000"
    fi
    rm -f /tmp/php-fpm.log
    mkfifo /tmp/php-fpm.log
    if [ "$(id -u)" = "0" ]; then chown www-data:www-data /tmp/php-fpm.log; fi
    cat > /tmp/php-fpm.conf <<CONF
[global]
daemonize = no
error_log = /tmp/php-fpm.log
log_limit = 8192
include = /usr/local/etc/php-fpm.d/www.conf
include = /tmp/php-fpm-pool.conf
CONF
    printf '[www]\nlisten = %s\nclear_env = no\ncatch_workers_output = yes\ndecorate_workers_output = no\n' "${FPM_LISTEN}" > /tmp/php-fpm-pool.conf
    set -- php-fpm -F -y /tmp/php-fpm.conf
    if [ "$(id -u)" = "0" ]; then
        su-exec www-data sh -c 'exec cat /tmp/php-fpm.log >&2' &
    else
        sh -c 'exec cat /tmp/php-fpm.log >&2' &
    fi
    # A development tree mounts the source, so the published Filament assets can be older than the installed
    # package. A missing script (the password field's, for one) breaks the sign-in form with a wrong error.
    if [ "${APP_ENV:-}" = "local" ] && [ -f artisan ]; then
        php artisan filament:assets --no-interaction >/dev/null 2>&1 || echo "Filament assets could not be published." >&2
    fi
    if [ -n "${EDGE_IDENTITY_CA_PRIVATE_KEY:-}" ] && ! su-exec www-data test -r "${EDGE_IDENTITY_CA_PRIVATE_KEY}"; then
        echo "The edge identity CA private key is not readable by the PHP-FPM worker; expected a restricted worker-readable secret." >&2
        exit 1
    fi
fi

# Root is used only above, to prepare storage. No process of this image keeps it, including the FPM master.
if [ "$(id -u)" = "0" ]; then
    exec su-exec www-data "$@"
fi

exec "$@"
