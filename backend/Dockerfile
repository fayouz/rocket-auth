# syntax=docker/dockerfile:1.7
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app
RUN install-php-extensions pdo_pgsql intl opcache apcu ldap zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --link docker/Caddyfile /etc/rocket-auth/Caddyfile
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=prod \
    SERVER_NAME=":80" \
    COMPOSER_ALLOW_SUPERUSER=1

FROM base AS vendor
COPY --link composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

FROM base AS prod
COPY --link --from=vendor /app/vendor ./vendor
COPY --link . .
RUN rm -f .env.local .env.*.local \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && mkdir -p var/cache var/log config/jwt \
    && chown -R www-data:www-data var config/jwt

COPY --link --chmod=755 docker/entrypoint.sh /usr/local/bin/app-entrypoint
# Version shown in the app and compared with the releases ("git describe --tags", passed by the CI).
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/rocket-auth/Caddyfile", "--adapter", "caddyfile"]
