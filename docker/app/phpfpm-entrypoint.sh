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

if [ "${APP_ENV:-prod}" != "dev" ] && [ "${STORAGE_ADAPTER:-local}" != "local" ]; then
    cache_dir="var/cache/${APP_ENV:-prod}"
    cache_marker="${cache_dir}/.runtime-storage-config"
    cache_key="${STORAGE_ADAPTER:-local}|${STORAGE_S3_ENDPOINT:-}|${STORAGE_S3_REGION:-}|${STORAGE_S3_BUCKET:-}|${STORAGE_S3_PREFIX:-}"

    if [ ! -f "${cache_marker}" ] || [ "$(cat "${cache_marker}")" != "${cache_key}" ]; then
        echo "[entrypoint] Rebuilding Symfony cache for runtime storage adapter '${STORAGE_ADAPTER}'."
        php bin/console cache:clear --no-interaction
        mkdir -p "${cache_dir}"
        printf '%s' "${cache_key}" > "${cache_marker}"
    fi
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
