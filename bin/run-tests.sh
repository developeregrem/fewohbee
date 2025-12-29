#!/usr/bin/env sh
set -euo pipefail

APP_ENV="${APP_ENV:-test}"
PHP_BIN="${PHP_BIN:-php}"
CONSOLE="$PHP_BIN bin/console"
PHPUNIT_BIN="${PHPUNIT_BIN:-./bin/phpunit}"

FIRST_RUN_USERNAME="${FIRST_RUN_USERNAME:-test-admin}"
FIRST_RUN_PASSWORD="${FIRST_RUN_PASSWORD:-ChangeMe123!}"
FIRST_RUN_FIRSTNAME="${FIRST_RUN_FIRSTNAME:-Test}"
FIRST_RUN_LASTNAME="${FIRST_RUN_LASTNAME:-Admin}"
FIRST_RUN_EMAIL="${FIRST_RUN_EMAIL:-test-admin@example.com}"
FIRST_RUN_ACCOMMODATION="${FIRST_RUN_ACCOMMODATION:-FewohBee Test Suite}"

run_console() {
    echo "→ $CONSOLE $*"
    APP_ENV="$APP_ENV" $CONSOLE "$@"
}

echo "==> Resetting database for env '$APP_ENV'"
run_console doctrine:database:drop --force --if-exists --no-interaction || true
run_console doctrine:database:create --if-not-exists --no-interaction
run_console doctrine:migrations:migrate --no-interaction

run_console app:first-run \
    --username="$FIRST_RUN_USERNAME" \
    --password="$FIRST_RUN_PASSWORD" \
    --first-name="$FIRST_RUN_FIRSTNAME" \
    --last-name="$FIRST_RUN_LASTNAME" \
    --email="$FIRST_RUN_EMAIL" \
    --accommodation-name="$FIRST_RUN_ACCOMMODATION" \
    --no-interaction

run_console doctrine:fixtures:load --no-interaction --append

APP_ENV="$APP_ENV" $PHPUNIT_BIN "$@"
