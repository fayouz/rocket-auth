#!/bin/sh
set -e

# JWT keys: mount them (config/jwt) in production; generated once otherwise.
php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction >/dev/null

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

php bin/console cache:warmup --no-interaction >/dev/null
chown -R www-data:www-data var config/jwt 2>/dev/null || true

exec "$@"
