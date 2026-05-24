#!/bin/sh
set -e

cd /var/www/html

# Legacy compatibility — see phpfpm-entrypoint.sh
if [ "${APP_ENV}" = "redis" ]; then
    export APP_ENV=prod
    export USE_REDIS_CACHE=true
fi

exec "$@"
