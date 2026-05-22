# syntax=docker/dockerfile:1.7
#
# nginx image for fewohbee.
# Pulls compiled AssetMapper output (public/assets/) from the phpfpm-prod stage,
# so the nginx image must be built AFTER phpfpm-prod with the same code base.
#
ARG PHPFPM_IMAGE=fewohbee-phpfpm:dev

FROM ${PHPFPM_IMAGE} AS php-assets

############################
FROM nginx:mainline-alpine

RUN apk add --no-cache gettext

# Application's static assets (incl. compiled public/assets/)
COPY --from=php-assets /var/www/html/public /var/www/html/public

# nginx configuration
COPY docker/nginx/site.conf /etc/nginx/conf.d/site.conf
COPY docker/nginx/site.conf.no-ssl /etc/nginx/conf.d/site.conf.no-ssl
COPY docker/nginx/site-enabled-http /etc/nginx/conf.d/site-enabled-http
COPY docker/nginx/site-enabled-https /etc/nginx/conf.d/site-enabled-https
COPY docker/nginx/snippets /etc/nginx/conf.d/snippets
COPY docker/nginx/templates /etc/nginx/conf.d/templates
COPY docker/nginx/entrypoint.sh /docker-entrypoint.sh

# Remove the default nginx config (replaced by site.conf)
RUN rm -f /etc/nginx/conf.d/default.conf \
        /etc/nginx/conf.d/server_name.active \
    && chmod +x /docker-entrypoint.sh

EXPOSE 80 443

ENTRYPOINT ["/docker-entrypoint.sh"]
