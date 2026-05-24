#!/bin/sh
set -e

: "${PHP_FPM_HOST:=php:9000}"
export PHP_FPM_HOST

# Select site config based on SSL mode
if [ "${REVERSE_PROXY:-false}" = "true" ]; then
    cp /etc/nginx/conf.d/site.conf.no-ssl /etc/nginx/conf.d/site.conf
fi

# Resolve ${HOST_NAME} placeholder in server-name include
envsubst '${HOST_NAME}' < /etc/nginx/conf.d/templates/server_name.template > /etc/nginx/conf.d/server_name.active

# Resolve ${PHP_FPM_HOST} in the fewohbee snippet (keeping nginx $-vars untouched)
envsubst '${PHP_FPM_HOST}' \
    < /etc/nginx/conf.d/site-enabled-https/01_fewohbee.snippet \
    > /etc/nginx/conf.d/site-enabled-https/01_fewohbee.snippet.tmp \
    && mv /etc/nginx/conf.d/site-enabled-https/01_fewohbee.snippet.tmp \
          /etc/nginx/conf.d/site-enabled-https/01_fewohbee.snippet

if [ "${REVERSE_PROXY:-false}" != "true" ]; then
    # Wait for SSL certificates to be provided by the acme container.
    echo "Waiting for SSL certificates ..."
    while [ ! -f "/certs/fullchain.pem" ] || [ ! -f "/certs/privkey.pem" ] || [ ! -f "/certs/dhparams.pem" ]; do
        sleep 2
    done
    echo "Certificates found, starting nginx."
fi

# Start nginx in the background so we can watch for cert changes
nginx -g 'daemon off;' &
NGINX_PID=$!

if [ "${REVERSE_PROXY:-false}" != "true" ]; then
    # Record the initial cert fingerprint
    CERT_HASH=$(md5sum /certs/fullchain.pem | cut -d' ' -f1)

    # Watch for certificate renewal and reload nginx when changed
    while kill -0 "$NGINX_PID" 2>/dev/null; do
        sleep 60
        NEW_HASH=$(md5sum /certs/fullchain.pem 2>/dev/null | cut -d' ' -f1)
        if [ -n "$NEW_HASH" ] && [ "$NEW_HASH" != "$CERT_HASH" ]; then
            CERT_HASH="$NEW_HASH"
            echo "Certificate changed, reloading nginx ..."
            nginx -s reload 2>/dev/null || true
        fi
    done
else
    # In reverse proxy mode just wait for nginx to exit
    while kill -0 "$NGINX_PID" 2>/dev/null; do
        sleep 60
    done
fi

wait "$NGINX_PID"
