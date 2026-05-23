# syntax=docker/dockerfile:1.7
#
# Unified application image for fewohbee.
# Final targets:
#   phpfpm-prod   – minimal php-fpm runtime, vendor without dev-deps, cache warmed
#   phpfpm-debug  – php-fpm with dev-deps + xdebug
#   cli-prod      – crond runtime for the dockerized setup
#
# All targets share base, vendor-prod, and app-prod stages, so composer install
# and cache:warmup run only once per build.
#
ARG PHP_VERSION=8.5
ARG PHP_EXTS="redis intl gd pdo_mysql exif"

############################
# Stage: base
############################
FROM php:${PHP_VERSION}-fpm-alpine AS base

ARG PHP_EXTS
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apk add --update --no-cache \
        git \
        zip \
        unzip \
        tzdata \
        icu \
        icu-data-full \
    && install-php-extensions ${PHP_EXTS}

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY docker/app/conf.ini /usr/local/etc/php/conf.d/conf.ini

WORKDIR /var/www/html

############################
# Stage: vendor-prod
############################
FROM base AS vendor-prod

COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist

############################
# Stage: vendor-debug
############################
FROM base AS vendor-debug

RUN install-php-extensions xdebug

COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-scripts --no-autoloader --prefer-dist

############################
# Stage: app-prod
#
# Shared by phpfpm-prod and cli-prod: brings in vendor + code, dumps optimized
# autoloader, warms cache and compiles assets. Final stages only add their
# entrypoint, user, and CMD.
############################
FROM base AS app-prod

# These ENVs are baked into the image and define its identity. Deployments
# should pick the right image tag (prod vs -debug) rather than override these
# at runtime — overrides cause cache/vendor mismatches (prod-warmed container
# trying to load dev-only bundles, etc).
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    USE_REDIS_CACHE=true

COPY --from=vendor-prod /var/www/html/vendor ./vendor
COPY --chown=82:82 . ./

# Build-time dummies kept inline so they do NOT persist into the image.
# Runtime TZ/DATABASE_URL/REDIS_HOST come from the deployment.
RUN TZ=UTC \
    DATABASE_URL="mysql://build:build@127.0.0.1:3306/build?serverVersion=10.11" \
    REDIS_HOST=127.0.0.1 \
    REDIS_IDX=1 \
    sh -c 'composer dump-autoload --no-dev --optimize --classmap-authoritative \
        && php bin/console cache:warmup --no-interaction \
        && php bin/console importmap:install --no-interaction \
        && php bin/console asset-map:compile --no-interaction' \
    && rm -rf tests docker .github .git .dockerignore \
    && chown -R 82:82 var public/assets

############################
# Stage: phpfpm-prod
############################
FROM app-prod AS phpfpm-prod

COPY docker/app/phpfpm-entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]

############################
# Stage: phpfpm-debug
############################
FROM base AS phpfpm-debug

# Same as app-prod: image identity, not user-overridable in normal deployments.
ENV APP_ENV=dev \
    APP_DEBUG=1 \
    USE_REDIS_CACHE=false

COPY --from=vendor-debug /var/www/html/vendor ./vendor
COPY --chown=82:82 . ./

RUN TZ=UTC composer dump-autoload \
    && mkdir -p var/cache var/log \
    && chown -R 82:82 var

COPY docker/app/phpfpm-entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]

############################
# Stage: cli-prod (crond for dockerized)
############################
FROM app-prod AS cli-prod

COPY docker/app/cli-entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

COPY docker/app/crontab /etc/crontabs/www-data
RUN chown root:root /etc/crontabs/www-data && chmod 600 /etc/crontabs/www-data

ENTRYPOINT ["/entrypoint.sh"]
CMD ["crond", "-f", "-L", "/dev/stdout"]
