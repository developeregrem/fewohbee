#!/bin/sh
set -e

cd /var/www/html

# Legacy compatibility — see phpfpm-entrypoint.sh
if [ "${APP_ENV}" = "redis" ]; then
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

exec "$@"
