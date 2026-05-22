#!/bin/sh
set -e

cd /var/www/html

# Backward compatibility: the legacy fewohbee convention used APP_ENV=redis to
# enable the Redis cache adapter. Symfony reserves APP_ENV for prod/dev/test, so
# we translate the old value to the new flag here. Existing dockerized .env files
# keep working without manual changes.
if [ "${APP_ENV}" = "redis" ]; then
    echo "[entrypoint] WARNING: APP_ENV=redis is deprecated. Treating as APP_ENV=prod USE_REDIS_CACHE=true."
    echo "[entrypoint]          Update your .env to: APP_ENV=prod and USE_REDIS_CACHE=true"
    export APP_ENV=prod
    export USE_REDIS_CACHE=true
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

if [ "${APP_ENV}" = "dev" ]; then
    php bin/console cache:clear --no-interaction || true
fi

# first arg looks like a flag → assume php-fpm
if [ "${1#-}" != "$1" ]; then
    set -- php-fpm "$@"
fi

exec "$@"
