# syntax=docker/dockerfile:1.7

ARG APP_IMAGE=fieldops-public-app:local
FROM ${APP_IMAGE} AS app-image

FROM nginx:1.29-alpine@sha256:5616878291a2eed594aee8db4dade5878cf7edcb475e59193904b198d9b830de AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app-image /var/www/html/public /var/www/html/public

EXPOSE 80
