FROM php:8.5-fpm-alpine AS php-base

RUN apk add --no-cache icu-libs libpq libzip nginx supervisor su-exec \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev \
    && docker-php-ext-install intl pcntl pdo_pgsql zip \
    && apk del .build-deps

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache COMPOSER_MAX_PARALLEL_HTTP=4 COMPOSER_IPRESOLVE=4 composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --classmap-authoritative

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/cdnf-entrypoint
RUN chmod +x /usr/local/bin/cdnf-entrypoint

ENTRYPOINT ["cdnf-entrypoint"]
CMD ["php-fpm", "-F"]

FROM php-base AS development
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache COMPOSER_MAX_PARALLEL_HTTP=4 COMPOSER_IPRESOLVE=4 composer install --no-interaction --no-progress --prefer-dist
RUN mv /app/vendor /opt/cdnf-vendor

FROM php-base AS production
