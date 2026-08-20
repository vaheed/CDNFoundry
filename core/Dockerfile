FROM php:8.5-fpm-alpine@sha256:9dc81f4086ea5402227a6bcc489b04b4baba12394624d9621faa92ed812fb8ee AS php-dependencies

RUN apk add --no-cache icu-libs libpq libzip libmaxminddb nginx supervisor su-exec postgresql-client restic \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev \
    && docker-php-ext-install intl pcntl pdo_pgsql zip \
    && apk del .build-deps

WORKDIR /app

COPY --from=composer:2@sha256:629d4ef35e75349d452851637d37a40ac33a09d6ac010139020603d79713d9bf /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    set -eu; \
    for attempt in 1 2 3 4 5; do \
        if COMPOSER_CACHE_DIR=/tmp/composer-cache COMPOSER_MAX_PARALLEL_HTTP=4 COMPOSER_IPRESOLVE=4 \
            composer install --no-dev --no-interaction --no-progress --prefer-install=auto --no-scripts --classmap-authoritative; then \
            exit 0; \
        fi; \
        if [ "${attempt}" -eq 5 ]; then exit 1; fi; \
        sleep $((attempt * 5)); \
    done

FROM node:26-alpine@sha256:233761595746769ebfdb6090f44fc7cdf818ae0ce62d2b37e0367723b9823e36 AS frontend-assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund

COPY resources ./resources
COPY app/Filament ./app/Filament
COPY vite.config.js ./
COPY --from=php-dependencies /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
COPY --from=php-dependencies /app/vendor/filament ./vendor/filament
RUN mkdir -p storage/framework/views \
    && npm run build

FROM scratch AS frontend-assets-export

COPY --from=frontend-assets /app/public/build /

FROM php-dependencies AS php-base

COPY . .
COPY --from=frontend-assets /app/public/build ./public/build
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && php artisan filament:assets \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/cdnf-entrypoint
COPY docker/backup/create.sh /usr/local/bin/cdnf-backup-create
COPY docker/backup/restore.sh /usr/local/bin/cdnf-backup-restore
RUN chmod +x /usr/local/bin/cdnf-entrypoint /usr/local/bin/cdnf-backup-create /usr/local/bin/cdnf-backup-restore

ENTRYPOINT ["cdnf-entrypoint"]
CMD ["php-fpm", "-F"]

FROM php-base AS development
RUN --mount=type=cache,target=/tmp/composer-cache \
    set -eu; \
    for attempt in 1 2 3 4 5; do \
        if COMPOSER_CACHE_DIR=/tmp/composer-cache COMPOSER_MAX_PARALLEL_HTTP=4 COMPOSER_IPRESOLVE=4 \
            composer install --no-interaction --no-progress --prefer-install=auto; then \
            exit 0; \
        fi; \
        if [ "${attempt}" -eq 5 ]; then exit 1; fi; \
        sleep $((attempt * 5)); \
    done
RUN mv /app/vendor /opt/cdnf-vendor

FROM php-base AS production
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry Laravel control plane" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
