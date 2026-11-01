#!/bin/sh
set -eu

# Database migrations are deliberately not run here. Deployments invoke
# `php artisan migrate --force` through the explicit migrate service.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage

if [ "${1:-}" = "php-fpm" ]; then
    # Listen only on the address of the private network shared with the web containers, so that
    # no other container on the control network can reach PHP-FPM. The alias resolves to this
    # container's address on that network only. The stock pool still sets the
    # worker user and process limits; this later file only replaces its listen address.
    if [ -n "${FPM_LISTEN_ALIAS:-}" ]; then
        FPM_LISTEN_ADDRESS="$(getent hosts "${FPM_LISTEN_ALIAS}" | awk 'NR == 1 { print $1 }')"
        if [ -z "${FPM_LISTEN_ADDRESS}" ]; then
            echo "The ${FPM_LISTEN_ALIAS} alias does not resolve; attach the service to the fpm network with that alias." >&2
            exit 1
        fi
        cat > /tmp/php-fpm.conf <<CONF
[global]
error_log = /proc/self/fd/2
daemonize = no
include = /usr/local/etc/php-fpm.d/*.conf
include = /tmp/php-fpm-listen.conf
CONF
        printf '[www]\nlisten = %s:9000\n' "${FPM_LISTEN_ADDRESS}" > /tmp/php-fpm-listen.conf
        set -- php-fpm -F -y /tmp/php-fpm.conf
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

if [ "$(id -u)" = "0" ] && [ "${1:-}" != "php-fpm" ]; then
    exec su-exec www-data "$@"
fi

exec "$@"
